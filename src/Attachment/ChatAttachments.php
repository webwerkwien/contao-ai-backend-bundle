<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Attachment;

use Symfony\Contracts\Service\ResetInterface;

/**
 * The files of the message being answered, for tools that work with them
 * (v1.0.0, stable surface).
 *
 * Filled by the chat after the checks, emptied when the request ends: a tool
 * reaches only the files of its own message, never those of an earlier one.
 * Symfony services outlive a request in a worker, hence reset() (autoconfigured
 * as kernel.reset) besides the controller's own clean-up.
 */
final class ChatAttachments implements ResetInterface
{
    /** @var list<ChatAttachment> */
    private array $files = [];

    /**
     * @param list<ChatAttachment> $files
     */
    public function set(array $files): void
    {
        $this->files = $files;
    }

    /**
     * @return list<ChatAttachment>
     */
    public function all(): array
    {
        return $this->files;
    }

    public function get(string $name): ?ChatAttachment
    {
        // As AttachmentReader tells duplicates apart: case-blind, umlauts included.
        $name = mb_strtolower($name);
        foreach ($this->files as $file) {
            if (mb_strtolower($file->name) === $name) {
                return $file;
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->files = [];
    }
}
