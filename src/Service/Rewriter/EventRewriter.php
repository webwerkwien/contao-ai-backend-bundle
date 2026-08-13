<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Rewriter;

use Contao\CalendarEventsModel;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Rewrites the editorial text fields of a single tl_calendar_events record.
 * Rewriteable: `title` (plain string), `teaser` (plain text). Identity
 * (alias, dates, author, location/coordinates) stays verbatim.
 */
class EventRewriter extends AbstractEntityRewriter
{
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

    protected function maxResultBytes(): int
    {
        return 5_000;
    }

    protected function fieldShape(string $field): string
    {
        return match ($field) {
            'title'  => 'a single-line event title (no markdown, no surrounding quotes)',
            'teaser' => 'a 1-3 sentence event teaser (plain text, no markdown)',
            default  => 'an editorial text snippet (plain text, no markdown)',
        };
    }
}
