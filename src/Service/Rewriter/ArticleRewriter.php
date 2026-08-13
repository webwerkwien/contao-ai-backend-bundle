<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Rewriter;

use Contao\ArticleModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Rewrites the editorial text fields of a single tl_article record.
 * Rewriteable: title, teaser. Identity (alias, inColumn, pid, author)
 * stays verbatim.
 */
class ArticleRewriter implements EntityRewriterInterface
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
        return 'tl_article' === $table;
    }

    public function rewrite(int $id, string $instructions, PlatformInterface $platform, string $model): array
    {
        $this->framework->initialize();

        $article = ArticleModel::findById($id);
        if (null === $article) {
            throw new \RuntimeException(\sprintf('Article %d nicht gefunden.', $id));
        }

        $fields  = [];
        $skipped = [];

        foreach (['title', 'teaser'] as $field) {
            $original = trim((string) ($article->$field ?? ''));
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
            'table'   => 'tl_article',
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
            'title'  => 'a single-line article title (no markdown)',
            'teaser' => 'an article teaser paragraph (HTML-allowed, preserve any inline tags exactly)',
            default  => 'an editorial text snippet',
        };

        $systemPrompt = <<<SYSTEM
You transform a single piece of editorial text according to the operator's instructions.

Form factor of the input: {$shape}.

Rules:
- Return ONLY the transformed text, nothing else. No preamble, no commentary, no markdown wrappers, no surrounding quotes.
- Preserve the same form factor as the input. For HTML inputs: preserve tag structure exactly, only transform the visible text inside.
- Keep proper nouns, brand names, dates, numbers, identifiers, URLs and HTML attributes verbatim unless the instructions explicitly say otherwise.
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
