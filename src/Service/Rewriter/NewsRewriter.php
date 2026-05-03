<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Rewriter;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\NewsModel;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Rewrites the editorial text fields of a single tl_news record.
 *
 * Rewriteable fields: `headline.value`, `teaser`, `subheadline`. Identity-
 * shaped columns (`alias`, `id`, `pid`, `author`, `date`, …) stay verbatim
 * because:
 *   - alias collisions break URLs
 *   - dates/IDs change record identity
 *   - author/pid are permission scopes, not editorial content
 *
 * Per-field invocation: each field is sent as its own platform request so
 * the LLM doesn't conflate them (a "shorten the teaser" instruction must
 * not get applied to the headline). The system prompt is field-typed.
 */
class NewsRewriter implements EntityRewriterInterface
{
    /**
     * Hard cap on the LLM result length, in bytes. Anything beyond is
     * treated as a hallucination (the LLM ignored "transform, don't
     * elaborate") and the field is skipped with a note. 5 KB covers any
     * reasonable headline/teaser/subheadline expansion (incl. translation
     * stretch factor of ~1.5×).
     */
    private const MAX_RESULT_BYTES = 5_000;

    /**
     * Below this fraction of the source length, suspect the LLM truncated
     * the content (e.g. returned only a tag instead of the rewritten
     * paragraph). Skip the field with a note rather than silently destroy
     * data.
     */
    private const MIN_LENGTH_RATIO = 0.3;

    public function __construct(
        private readonly ContaoFramework $framework,
    ) {
    }

    public function supports(string $table): bool
    {
        return 'tl_news' === $table;
    }

    public function rewrite(int $id, string $instructions, PlatformInterface $platform, string $model): array
    {
        $this->framework->initialize();

        $news = NewsModel::findById($id);
        if (null === $news) {
            throw new \RuntimeException(\sprintf('News-Eintrag %d nicht gefunden.', $id));
        }

        $fields   = [];
        $skipped  = [];

        // headline is a serialized {unit, value} payload. We only rewrite the
        // value; the unit (h1/h2/…) is structural and stays. The serialized
        // form is reconstructed for the *_update payload so news_update can
        // write it back without further coercion.
        $headlineRaw   = (string) ($news->headline ?? '');
        $headlineParts = $this->extractHeadlineParts($headlineRaw);
        if ('' !== $headlineParts['value']) {
            $newValue = $this->rewriteField('headline', $headlineParts['value'], $instructions, $platform, $model);
            if (null !== $newValue) {
                // Roh-Wert zurückgeben — NewsUpdateCommand::preProcessFields()
                // wrappt String-Headlines in den input-unit-serialize-Container.
                // Hier zusätzlich zu serialisieren würde das Wrapping doppeln
                // (live beobachtet 2026-05-03: `a:2:{value:"a:2:{value:'…'}"}`).
                $fields['headline'] = $newValue;
            } else {
                $skipped['headline'] = 'LLM lieferte ungültiges Ergebnis (zu kurz, leer oder zu lang)';
            }
        } else {
            $skipped['headline'] = 'leer im Ausgangsdatensatz';
        }

        foreach (['teaser', 'subheadline'] as $field) {
            $original = trim((string) ($news->$field ?? ''));
            if ('' === $original) {
                $skipped[$field] = 'leer im Ausgangsdatensatz';
                continue;
            }
            $newValue = $this->rewriteField($field, $original, $instructions, $platform, $model);
            if (null === $newValue) {
                $skipped[$field] = 'LLM lieferte ungültiges Ergebnis (zu kurz, leer oder zu lang)';
                continue;
            }
            $fields[$field] = $newValue;
        }

        return [
            'id'      => $id,
            'table'   => 'tl_news',
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
            return ['unit' => 'h1', 'value' => ''];
        }
        $decoded = @unserialize($serialized, ['allowed_classes' => false]);
        if (\is_array($decoded)) {
            return [
                'unit'  => (string) ($decoded['unit'] ?? 'h1'),
                'value' => (string) ($decoded['value'] ?? ''),
            ];
        }
        // Plain string headline (legacy or not yet input-unit-wrapped).
        return ['unit' => 'h1', 'value' => $serialized];
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
        $systemPrompt = $this->buildSystemPrompt($field, $instructions);

        $messages = new MessageBag(
            Message::forSystem($systemPrompt),
            Message::ofUser($original),
        );

        try {
            // Anthropic erwartet `max_tokens`, OpenAI `max_output_tokens` —
            // statt platform-spezifisch zu switchen lassen wir die Defaults
            // der jeweiligen Bridge greifen. Editorial-Felder sind kurz, der
            // Default-Cap reicht für Headline/Teaser/Subheadline mit Marge.
            $result = $platform->invoke($model, $messages, [
                'temperature' => 0.3,
            ]);
        } catch (\Throwable $e) {
            // Platform error (rate-limit, network, malformed key) — fail this
            // field but keep going for the rest. Surfaced as `skipped` to the
            // operator who can re-run after fixing the cause.
            return null;
        }

        $rewritten = trim($this->resultToText($result));
        if (!$this->isPlausible($rewritten, $original)) {
            return null;
        }
        return $rewritten;
    }

    /**
     * Field-typed system prompt. Each field gets context about its form factor
     * (headline, teaser, subheadline) so the LLM doesn't reformat a headline
     * into a paragraph or vice versa.
     */
    private function buildSystemPrompt(string $field, string $instructions): string
    {
        $shape = match ($field) {
            'headline'    => 'a single-line news headline (no punctuation at end unless rhetorical, no markdown)',
            'teaser'      => 'a 1-3 sentence article teaser (plain text, no markdown, no leading whitespace)',
            'subheadline' => 'a single-line subheadline that complements the headline (plain text)',
            default       => 'an editorial text snippet (plain text, no markdown)',
        };

        return <<<SYSTEM
You transform a single piece of editorial text according to the operator's instructions.

Form factor of the input: {$shape}.

Rules:
- Return ONLY the transformed text, nothing else. No preamble, no commentary, no markdown wrappers, no surrounding quotes.
- Preserve the same form factor as the input. A headline stays a single line, a teaser stays a paragraph.
- Keep proper nouns, brand names, dates, numbers, and identifiers verbatim unless the instructions explicitly say otherwise.
- If the instructions cannot be applied (e.g. the input is empty or too short), return the input verbatim.
- Do not add new factual claims. Stick to what the input says.

Operator's instructions: {$instructions}
SYSTEM;
    }

    /**
     * Extract plain text from a TextResult. symfony/ai's PlatformInterface
     * returns different result classes per provider (TextResult, AssistantMessage,
     * …); we try the common surface (`asText()`) and fall back to `(string)`.
     */
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

    /**
     * Sanity check: result is not empty, not absurdly longer than the source,
     * and not way too short. Catches the most common LLM regression modes
     * (returned empty string, returned a JSON wrapper, dropped the content
     * entirely). Doesn't catch semantic errors — the operator review-step
     * before publishing is the last line of defence.
     */
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
