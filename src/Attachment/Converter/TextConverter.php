<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Attachment\Converter;

use Webwerkwien\ContaoAiBackendBundle\Attachment\AttachmentContent;
use Webwerkwien\ContaoAiBackendBundle\Attachment\AttachmentConverterInterface;
use Webwerkwien\ContaoAiBackendBundle\Attachment\ChatAttachment;
use Webwerkwien\ContaoAiBackendBundle\Exception\AttachmentRefusedException;

/**
 * Plain text, Markdown and CSV, read as text with the file name in front.
 *
 * Refused above MAX_CHARS, as a file above maxFileSize is: Contao's upload
 * limit allows 2 MB, which as text is about half a million tokens, more than any
 * context window. Cutting instead would have the model answer on a part while
 * sounding complete; the refusal says how long the file is and what fits.
 */
final class TextConverter implements AttachmentConverterInterface
{
    public const MAX_CHARS = 100_000;

    public function extensions(): array
    {
        return ['txt', 'md', 'csv'];
    }

    public function acceptsMime(string $extension, string $mime): bool
    {
        return str_starts_with($mime, 'text/') || 'application/csv' === $mime;
    }

    public function convert(ChatAttachment $file): array
    {
        $text = $file->bytes;
        // Excel and older editors write Windows-1252, not UTF-8.
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }

        $length = mb_strlen($text);
        if ($length > self::MAX_CHARS) {
            throw new AttachmentRefusedException('attachment_too_long', [$file->name, $length, self::MAX_CHARS]);
        }

        return [AttachmentContent::text(self::framed($file->name, $text))];
    }

    /**
     * The text inside `<attachment_data>`, which the system prompt names as material,
     * never instructions (v1.1.0) — as tool outputs are framed. A closing tag inside the
     * file is broken up, so the text cannot end the frame and speak after it; the name
     * is escaped like an attribute.
     *
     * A model reads past what a byte comparison sees: invisible characters (zero-width
     * space, soft hyphen, no-break space) between the parts and lookalikes of `<` and
     * `/` (fullwidth, division slash, fraction slash) still read as the closing tag, so
     * they count as part of it.
     */
    public static function framed(string $name, string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        $gap = '[\s\p{Z}\p{Cf}]*';
        $broken = preg_replace(
            '#([<\x{FF1C}\x{FE64}])('.$gap.'[/\x{2215}\x{2044}\x{FF0F}\x{29F8}]'.$gap.'attachment'.$gap.'_'.$gap.'data)#iu',
            '$1\\\\$2',
            $text,
        );
        if (null === $broken) {
            throw new AttachmentRefusedException('attachment_unreadable', [$name]);
        }

        return \sprintf(
            "<attachment_data name=\"%s\">\n%s\n</attachment_data>",
            htmlspecialchars($name, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            $broken,
        );
    }
}
