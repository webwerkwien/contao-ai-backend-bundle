<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Attachment;

/**
 * What a converter makes of a file: text, a document or an image (v1.0.0).
 *
 * Our own type on purpose. symfony/ai is still 0.x and may rename its content
 * classes in any minor; a converter written against this one keeps working, and
 * only AcceptedAttachments has to follow symfony/ai.
 */
final class AttachmentContent
{
    public const TEXT     = 'text';
    public const DOCUMENT = 'document';
    public const IMAGE    = 'image';

    private function __construct(
        public readonly string $kind,
        public readonly string $data,
        public readonly string $mime,
    ) {
    }

    public static function text(string $text): self
    {
        return new self(self::TEXT, $text, 'text/plain');
    }

    public static function document(string $bytes, string $mime = 'application/pdf'): self
    {
        return new self(self::DOCUMENT, $bytes, $mime);
    }

    public static function image(string $bytes, string $mime): self
    {
        return new self(self::IMAGE, $bytes, $mime);
    }
}
