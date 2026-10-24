<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Attachment;

/**
 * A file sent with one chat message, checked and decoded (v1.0.0).
 *
 * It lives for the request that carried it: the chat keeps "Anhang: name" in its
 * history, never the file. Part of the stable surface: converters receive it, and
 * tools of an add-on find it through ChatAttachments.
 */
final class ChatAttachment
{
    public function __construct(
        public readonly string $name,
        public readonly string $extension,
        public readonly string $mime,
        public readonly string $bytes,
    ) {
    }

    public function size(): int
    {
        return \strlen($this->bytes);
    }
}
