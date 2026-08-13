<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Rewriter;

use Contao\NewsModel;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Rewrites the editorial text fields of a single tl_news record.
 *
 * Rewriteable fields: `headline`, `teaser`, `subheadline`. Identity-shaped
 * columns (`alias`, `id`, `pid`, `author`, `date`, …) stay verbatim because:
 *   - alias collisions break URLs
 *   - dates/IDs change record identity
 *   - author/pid are permission scopes, not editorial content
 *
 * Per-field invocation: each field is sent as its own platform request so the
 * LLM doesn't conflate them (a "shorten the teaser" instruction must not get
 * applied to the headline). The system prompt is field-typed.
 */
class NewsRewriter extends AbstractEntityRewriter
{
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

        $fields  = [];
        $skipped = [];

        // tl_news.headline is a PLAIN TEXT field — it is the news title
        // (Contao DCA: inputType 'text', varchar(255)). Legacy records may still
        // hold a serialized {unit, value} payload written by NewsCreateCommand up
        // to core-bundle v0.2.3; extractHeadlineParts() unwraps those and passes
        // plain strings through unchanged. Repair legacy rows with
        // `contao:news:repair-headlines`.
        $headlineValue = $this->extractHeadlineValue((string) ($news->headline ?? ''));

        if ('' !== $headlineValue) {
            $newValue = $this->rewriteField('headline', $headlineValue, $instructions, $platform, $model);
            if (null !== $newValue) {
                // Plain string — news_update writes tl_news.headline verbatim,
                // which is what Contao expects for this column.
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
     * Unwraps a legacy serialized {unit, value} headline; plain strings — the
     * correct form for tl_news — are returned unchanged.
     */
    private function extractHeadlineValue(string $stored): string
    {
        if ('' === $stored) {
            return '';
        }

        $decoded = @unserialize($stored, ['allowed_classes' => false]);

        return \is_array($decoded) ? (string) ($decoded['value'] ?? '') : $stored;
    }

    protected function maxResultBytes(): int
    {
        return 5_000;
    }

    protected function formFactorHint(): string
    {
        return 'A headline stays a single line, a teaser stays a paragraph.';
    }

    protected function fieldShape(string $field): string
    {
        return match ($field) {
            'headline'    => 'a single-line news headline (no punctuation at end unless rhetorical, no markdown)',
            'teaser'      => 'a 1-3 sentence article teaser (plain text, no markdown, no leading whitespace)',
            'subheadline' => 'a single-line subheadline that complements the headline (plain text)',
            default       => 'an editorial text snippet (plain text, no markdown)',
        };
    }
}
