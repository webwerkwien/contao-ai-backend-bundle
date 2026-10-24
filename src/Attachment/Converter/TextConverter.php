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

        return [AttachmentContent::text(\sprintf("Anhang „%s“:\n\n%s", $file->name, $text))];
    }
}
