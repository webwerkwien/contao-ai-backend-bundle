<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Rewriter;

use Contao\FaqModel;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Rewrites the editorial text fields of a single tl_faq record.
 * Rewriteable: `question` (plain string), `answer` (HTML rich-text). Identity
 * (alias, author, addImage, singleSRC) stays verbatim.
 *
 * Note: tl_faq.answer is rich-text HTML in stock Contao. The LLM is told about
 * the form factor so it preserves inline tags; aggressive markdown conversion
 * would corrupt the field.
 */
class FaqRewriter extends AbstractEntityRewriter
{
    public function supports(string $table): bool
    {
        return 'tl_faq' === $table;
    }

    public function rewrite(int $id, string $instructions, PlatformInterface $platform, string $model): array
    {
        $this->framework->initialize();

        $faq = FaqModel::findById($id);
        if (null === $faq) {
            throw new \RuntimeException(\sprintf('FAQ-Eintrag %d nicht gefunden.', $id));
        }

        $fields  = [];
        $skipped = [];

        foreach (['question', 'answer'] as $field) {
            $original = trim((string) ($faq->$field ?? ''));
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
            'table'   => 'tl_faq',
            'fields'  => $fields,
            'skipped' => $skipped,
        ];
    }

    protected function maxResultBytes(): int
    {
        return 20_000;
    }

    protected function preservesHtml(): bool
    {
        return true;
    }

    protected function fieldShape(string $field): string
    {
        return match ($field) {
            'question' => 'a single-line FAQ question (plain text, no markdown, ends with a question mark in the source language)',
            'answer'   => 'an FAQ answer formatted as HTML rich-text (paragraphs with <p>, optional inline tags like <a> <strong> <em>; preserve existing HTML structure exactly, only transform the human-readable text content)',
            default    => 'an editorial text snippet',
        };
    }
}
