<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Attachment;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Webwerkwien\ContaoAiBackendBundle\Exception\AttachmentRefusedException;

/**
 * Reads one kind of file for the chat (v1.0.0, stable surface).
 *
 * Implement it in any bundle with autoconfigure on, and the chat offers the
 * extensions in its file picker, as far as Contao's upload types allow them.
 * The free bundle reads PDF, images and plain text; an add-on adds more.
 *
 * One converter per extension: the first one wins, in the order of the tag's
 * `priority` (highest first, the free ones have 0). A bundle that tags its own
 * converter with a higher priority takes over an extension of the free bundle.
 */
#[AutoconfigureTag(self::TAG)]
interface AttachmentConverterInterface
{
    public const TAG = 'contao_ai_backend.attachment_converter';

    /**
     * @return list<string> lower-case extensions without the dot
     */
    public function extensions(): array;

    /**
     * Whether the type finfo detected fits the extension: a renamed file is refused.
     */
    public function acceptsMime(string $extension, string $mime): bool;

    /**
     * @return list<AttachmentContent>
     *
     * @throws AttachmentRefusedException when the file cannot be read
     */
    public function convert(ChatAttachment $file): array;
}
