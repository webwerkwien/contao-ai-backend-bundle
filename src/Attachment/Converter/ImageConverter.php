<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Attachment\Converter;

use Webwerkwien\ContaoAiBackendBundle\Attachment\AttachmentContent;
use Webwerkwien\ContaoAiBackendBundle\Attachment\AttachmentConverterInterface;
use Webwerkwien\ContaoAiBackendBundle\Attachment\ChatAttachment;

/**
 * Raster images, as the providers take them. No SVG: it can carry scripts, and
 * models do not read it as an image.
 */
final class ImageConverter implements AttachmentConverterInterface
{
    private const TYPES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    public function extensions(): array
    {
        return array_keys(self::TYPES);
    }

    public function acceptsMime(string $extension, string $mime): bool
    {
        return (self::TYPES[$extension] ?? null) === $mime;
    }

    public function convert(ChatAttachment $file): array
    {
        return [AttachmentContent::image($file->bytes, self::TYPES[$file->extension] ?? $file->mime)];
    }
}
