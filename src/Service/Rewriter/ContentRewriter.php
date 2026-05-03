<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Rewriter;

use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Rewrites the editorial text fields of a single tl_content record.
 *
 * Type-aware rewriteable fields:
 *   - text type: `text` (HTML rich-text)
 *   - headline type: `headline` (input-unit serialized {unit, value})
 *
 * Other content types (image, accordion, gallery, module, …) have
 * structured payloads that the LLM shouldn't touch — they're skipped
 * silently with a note. Add per-type handlers when use-cases appear.
 *
 * Headline write-back: returns the RAW value (string), NewsUpdateCommand-
 * style preProcessFields in ContentUpdateCommand wraps it. (Same
 * fix as Phase 9.3 NewsRewriter — see "Doppel-Serialisierung" Fallstrick.)
 */
class ContentRewriter implements EntityRewriterInterface
{
    private const MAX_RESULT_BYTES = 50_000;
    private const MIN_LENGTH_RATIO = 0.3;

    public function __construct(
        private readonly ContaoFramework $framework,
    ) {
    }

    public function supports(string $table): bool
    {
        return 'tl_content' === $table;
    }

    public function rewrite(int $id, string $instructions, PlatformInterface $platform, string $model): array
    {
        $this->framework->initialize();

        $content = ContentModel::findById($id);
        if (null === $content) {
            throw new \RuntimeException(\sprintf('Content-Element %d nicht gefunden.', $id));
        }

        $fields  = [];
        $skipped = [];
        $type    = (string) ($content->type ?? '');

        // Headline-Feld: alle Typen, die ein input-unit-Feld haben (text, headline,
        // hyperlink, …) — wir versuchen es generisch und prüfen Inhalt.
        $headlineRaw = (string) ($content->headline ?? '');
        if ('' !== $headlineRaw) {
            $parts = $this->extractHeadlineParts($headlineRaw);
            if ('' !== $parts['value']) {
                $newValue = $this->rewriteField('headline', $parts['value'], $instructions, $platform, $model);
                if (null !== $newValue) {
                    $fields['headline'] = $newValue; // Roh-Wert, ContentUpdate wrappt
                } else {
                    $skipped['headline'] = 'LLM lieferte ungültiges Ergebnis';
                }
            }
        }

        // Text-Feld: nur bei type='text' (HTML rich-text). Andere Typen (z.B.
        // accordion, gallery) haben `text` zwar evtl. befüllt, aber strukturell
        // — Rewrite würde DCA-Inhalte zerstören.
        if ('text' === $type) {
            $original = trim((string) ($content->text ?? ''));
            if ('' === $original) {
                $skipped['text'] = 'leer im Ausgangsdatensatz';
            } else {
                $newValue = $this->rewriteField('text', $original, $instructions, $platform, $model);
                if (null === $newValue) {
                    $skipped['text'] = 'LLM lieferte ungültiges Ergebnis (zu kurz, leer oder zu lang)';
                } else {
                    $fields['text'] = $newValue;
                }
            }
        } elseif ([] === $fields && '' === $headlineRaw) {
            // Kein einziges rewriteables Feld auf diesem Element-Typ.
            $skipped['_type'] = \sprintf('Content-Typ "%s" hat keine rewriteable-Felder', $type);
        }

        return [
            'id'      => $id,
            'table'   => 'tl_content',
            'fields'  => $fields,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array{unit: string, value: string}
     */
    private function extractHeadlineParts(string $serialized): array
    {
        if ('' === $serialized) {
            return ['unit' => 'h2', 'value' => ''];
        }
        $decoded = @unserialize($serialized, ['allowed_classes' => false]);
        if (\is_array($decoded)) {
            return [
                'unit'  => (string) ($decoded['unit'] ?? 'h2'),
                'value' => (string) ($decoded['value'] ?? ''),
            ];
        }
        return ['unit' => 'h2', 'value' => $serialized];
    }

    private function rewriteField(
        string $field,
        string $original,
        string $instructions,
        PlatformInterface $platform,
        string $model,
    ): ?string {
        // Phase-9.4-Fix: sehr kurze Inputs verleiten manche Modelle zu
        // Klärungs-Antworten ('I need a headline to transform...'). Pass-through
        // verbatim für <4 Zeichen, Refusal-Detection für längere Outputs siehe isPlausible().
        if (mb_strlen($original) < 4) {
            return $original;
        }
        $shape = match ($field) {
            'headline' => 'a single-line headline (no markdown, no surrounding quotes)',
            'text'     => 'editorial rich-text content formatted as HTML (paragraphs with <p>, optional inline tags like <a> <strong> <em> <ul> <ol> <li>; preserve existing HTML structure exactly, only transform the visible text inside)',
            default    => 'an editorial text snippet',
        };

        $systemPrompt = <<<SYSTEM
You transform a single piece of editorial text according to the operator's instructions.

Form factor of the input: {$shape}.

Rules:
- Return ONLY the transformed text, nothing else. No preamble, no commentary, no markdown wrappers, no surrounding quotes.
- Preserve the same form factor as the input. For HTML inputs: preserve tag structure and attributes exactly, only transform the visible text inside.
- Keep proper nouns, brand names, dates, numbers, identifiers, URLs and HTML attributes verbatim unless the instructions explicitly say otherwise.
- If the instructions cannot be applied (e.g. the input is empty or too short), return the input verbatim.
- Do not add new factual claims. Stick to what the input says.

Operator's instructions: {$instructions}
SYSTEM;

        try {
            $result = $platform->invoke($model, new MessageBag(
                Message::forSystem($systemPrompt),
                Message::ofUser($original),
            ), [
                'temperature' => 0.3,
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        $rewritten = trim($this->resultToText($result));
        if (!$this->isPlausible($rewritten, $original)) {
            return null;
        }
        return $rewritten;
    }

    private function resultToText(mixed $result): string
    {
        if (\is_object($result) && method_exists($result, 'asText')) {
            return (string) $result->asText();
        }
        if (\is_object($result) && method_exists($result, '__toString')) {
            return (string) $result;
        }
        return (string) $result;
    }

    private function isPlausible(string $rewritten, string $original): bool
    {
        if ('' === $rewritten) {
            return false;
        }
        if (\strlen($rewritten) > self::MAX_RESULT_BYTES) {
            return false;
        }
        // Phase-9.4-Fix: Refusal-Detection. Bei kurzen Source-Inputs antworten
        // manche Modelle mit Klärungs-Phrasen. Output >= 1.5x länger UND mit typischen
        // Refusal-Phrasen anfangend = nicht akzeptieren.
        if (\strlen($rewritten) >= \strlen($original) * 1.5 && preg_match(
            '/^(I (need|require|don\'t see|do not see|cannot|am unable|notice|see that)|Please (provide|share|give|specify)|Could you (provide|share|give|specify|please)|It (seems|appears|looks)\\s+(like|that)|The (input|text|content)\\s+(is|appears|seems)|Ich (brauche|benötige|kann|sehe)|Bitte (geben|stellen|teilen|liefern))/i',
            $rewritten
        )) {
            return false;
        }
        $sourceLen = \strlen($original);
        if ($sourceLen >= 30 && \strlen($rewritten) < $sourceLen * self::MIN_LENGTH_RATIO) {
            return false;
        }
        return true;
    }
}
