<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Rewriter;

use Contao\PageModel;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Rewrites the editorial text fields of a single tl_page record.
 * Rewriteable: title, pageTitle, description. SEO keywords stay verbatim
 * (operator-curated; LLM rewriting often hallucinates new ones).
 *
 * Identity (alias, type, parent, layout) stays verbatim.
 */
class PageRewriter extends AbstractEntityRewriter
{
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

    protected function maxResultBytes(): int
    {
        return 5_000;
    }

    protected function fieldShape(string $field): string
    {
        return match ($field) {
            'title'       => 'a single-line page navigation title (short, max ~50 chars)',
            'pageTitle'   => 'a single-line HTML <title>-tag value (SEO-relevant, ~50-60 chars)',
            'description' => 'a 1-2 sentence meta description (SEO-relevant, ~150-160 chars)',
            default       => 'an editorial text snippet',
        };
    }
}
