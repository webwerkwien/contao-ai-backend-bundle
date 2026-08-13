<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Rewriter;

use Contao\ContentModel;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Rewrites the editorial text fields of a single tl_content record.
 *
 * Type-aware rewriteable fields:
 *   - text type: `text` (HTML rich-text)
 *   - headline: `headline` (input-unit serialized {unit, value})
 *
 * Other content types (image, accordion, gallery, module, …) have structured
 * payloads that the LLM shouldn't touch — they're skipped with a note. Add
 * per-type handlers when use-cases appear.
 *
 * Headline write-back returns the RAW value; ContentUpdateCommand wraps it into
 * the input-unit container. Unlike tl_news, tl_content.headline genuinely is an
 * `inputUnit` field, so the wrapping belongs there.
 */
class ContentRewriter extends AbstractEntityRewriter
{
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

        // Headline: every type that has an input-unit field (text, headline,
        // hyperlink, …) — attempted generically, guarded by the content check.
        $headlineRaw = (string) ($content->headline ?? '');
        if ('' !== $headlineRaw) {
            $parts = $this->extractHeadlineParts($headlineRaw);
            if ('' !== $parts['value']) {
                $newValue = $this->rewriteField('headline', $parts['value'], $instructions, $platform, $model);
                if (null !== $newValue) {
                    $fields['headline'] = $newValue; // raw value, ContentUpdate wraps it
                } else {
                    $skipped['headline'] = 'LLM lieferte ungültiges Ergebnis';
                }
            }
        }

        // Text field: only for type='text' (HTML rich-text). Other types (e.g.
        // accordion, gallery) may have `text` populated too, but structurally —
        // rewriting it would destroy DCA content.
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

    protected function maxResultBytes(): int
    {
        return 50_000;
    }

    protected function preservesHtml(): bool
    {
        return true;
    }

    protected function fieldShape(string $field): string
    {
        return match ($field) {
            'headline' => 'a single-line headline (no markdown, no surrounding quotes)',
            'text'     => 'editorial rich-text content formatted as HTML (paragraphs with <p>, optional inline tags like <a> <strong> <em> <ul> <ol> <li>; preserve existing HTML structure exactly, only transform the visible text inside)',
            default    => 'an editorial text snippet',
        };
    }
}
