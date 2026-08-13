<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Rewriter;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Rewrites the editorial text fields of a single tl_page record.
 * Rewriteable: title, pageTitle, description. SEO-keywords stay verbatim
 * (operator-curated; LLM-rewriting often hallucinates new ones).
 *
 * Identity (alias, type, parent, layout) stays verbatim.
 */
class PageRewriter implements EntityRewriterInterface
{
    use UntrustedInputTrait;

    private const MAX_RESULT_BYTES = 5_000;
    private const MIN_LENGTH_RATIO = 0.3;

    public function __construct(
        private readonly ContaoFramework $framework,
    ) {
    }

    public function supports(string $table): bool
    {
        return 'tl_page' === $table;
    }

    public function rewrite(int $id, string $instructions, PlatformInterface $platform, string $model): array
    {
        $this->framework->initialize();

        $page = PageModel::findById($id);
        if (null === $page) {
            throw new \RuntimeException(\sprintf('Page %d nicht gefunden.', $id));
        }

        $fields  = [];
        $skipped = [];

        foreach (['title', 'pageTitle', 'description'] as $field) {
            $original = trim((string) ($page->$field ?? ''));
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
            'table'   => 'tl_page',
            'fields'  => $fields,
            'skipped' => $skipped,
        ];
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
            'title'       => 'a single-line page navigation title (short, max ~50 chars)',
            'pageTitle'   => 'a single-line HTML <title>-tag value (SEO-relevant, ~50-60 chars)',
            'description' => 'a 1-2 sentence meta description (SEO-relevant, ~150-160 chars)',
            default       => 'an editorial text snippet',
        };

        $systemPrompt = <<<SYSTEM
You transform a single piece of editorial text according to the operator's instructions.

Form factor of the input: {$shape}.

Rules:
- Return ONLY the transformed text, nothing else. No preamble, no commentary, no markdown wrappers, no surrounding quotes.
- Preserve the same form factor as the input.
- Keep proper nouns, brand names, dates, numbers, and identifiers verbatim unless the instructions explicitly say otherwise.
- If the instructions cannot be applied (e.g. the input is empty or too short), return the input verbatim.
- Do not add new factual claims. Stick to what the input says.

{$this->untrustedInputRule()}

Operator's instructions: {$instructions}
SYSTEM;

        try {
            $result = $platform->invoke($model, new MessageBag(
                Message::forSystem($systemPrompt),
                Message::ofUser($this->wrapUntrustedInput($original)),
            ), [
                'temperature' => 0.3,
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        $rewritten = $this->stripInputWrapper(trim($this->resultToText($result)));
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
