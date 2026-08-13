<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Rewriter;

use Contao\ArticleModel;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Rewrites the editorial text fields of a single tl_article record.
 * Rewriteable: title, teaser. Identity (alias, inColumn, pid, author)
 * stays verbatim.
 */
class ArticleRewriter extends AbstractEntityRewriter
{
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

    protected function maxResultBytes(): int
    {
        return 5_000;
    }

    protected function preservesHtml(): bool
    {
        return true;
    }

    protected function fieldShape(string $field): string
    {
        return match ($field) {
            'title'  => 'a single-line article title (no markdown)',
            'teaser' => 'an article teaser paragraph (HTML-allowed, preserve any inline tags exactly)',
            default  => 'an editorial text snippet',
        };
    }
}
