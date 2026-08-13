<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Rewriter;

use Contao\CalendarEventsModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Rewrites the editorial text fields of a single tl_calendar_events record.
 * Rewriteable: `title` (plain string), `teaser` (plain text). Identity
 * (alias, dates, author, location/coordinates) stays verbatim.
 */
class EventRewriter implements EntityRewriterInterface
{
    use UntrustedInputTrait;
    use RefusalDetectionTrait;

    private const MAX_RESULT_BYTES = 5_000;
    private const MIN_LENGTH_RATIO = 0.3;

    public function __construct(
        private readonly ContaoFramework $framework,
    ) {
    }

    public function supports(string $table): bool
    {
        return 'tl_calendar_events' === $table;
    }

    public function rewrite(int $id, string $instructions, PlatformInterface $platform, string $model): array
    {
        $this->framework->initialize();

        $event = CalendarEventsModel::findById($id);
        if (null === $event) {
            throw new \RuntimeException(\sprintf('Event %d nicht gefunden.', $id));
        }

        $fields  = [];
        $skipped = [];

        foreach (['title', 'teaser'] as $field) {
            $original = trim((string) ($event->$field ?? ''));
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
            'table'   => 'tl_calendar_events',
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
            'title'  => 'a single-line event title (no markdown, no surrounding quotes)',
            'teaser' => 'a 1-3 sentence event teaser (plain text, no markdown)',
            default  => 'an editorial text snippet (plain text, no markdown)',
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
        // Refusal-Detection: siehe RefusalDetectionTrait (Pattern zentral,
        // weil es zwischen den Rewritern bereits auseinandergelaufen war).
        if ($this->looksLikeRefusal($rewritten, $original)) {
            return false;
        }
        $sourceLen = \strlen($original);
        if ($sourceLen >= 30 && \strlen($rewritten) < $sourceLen * self::MIN_LENGTH_RATIO) {
            return false;
        }
        return true;
    }
}
