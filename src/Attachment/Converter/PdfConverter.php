<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Attachment\Converter;

use Webwerkwien\ContaoAiBackendBundle\Attachment\AttachmentContent;
use Webwerkwien\ContaoAiBackendBundle\Attachment\AttachmentConverterInterface;
use Webwerkwien\ContaoAiBackendBundle\Attachment\ChatAttachment;

/**
 * A PDF goes to the model as it is: models that read PDF see text and pages.
 */
final class PdfConverter implements AttachmentConverterInterface
{
    public function extensions(): array
    {
        return ['pdf'];
    }

    public function acceptsMime(string $extension, string $mime): bool
    {
        return 'application/pdf' === $mime;
    }

    public function convert(ChatAttachment $file): array
    {
        return [AttachmentContent::document($file->bytes, 'application/pdf')];
    }
}
