<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Attachment;

use Symfony\AI\Platform\Message\Content\ContentInterface;
use Symfony\AI\Platform\Message\Content\Document;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\Text;

/**
 * The files of one message after the checks, and what the model gets of them.
 *
 * @internal the one place that follows symfony/ai's content classes
 */
final class AcceptedAttachments
{
    /**
     * @param list<ChatAttachment>    $files
     * @param list<AttachmentContent> $contents
     */
    public function __construct(
        public readonly array $files,
        public readonly array $contents,
    ) {
    }

    /**
     * The user's text with one "Anhang: name" line per file: what the history keeps,
     * so a later turn knows a file was there without ever sending it again.
     */
    public function turnText(string $userInput): string
    {
        if ([] === $this->files) {
            return $userInput;
        }

        $lines = array_map(static fn (ChatAttachment $file): string => 'Anhang: ' . $file->name, $this->files);

        return trim(trim($userInput) . "\n\n" . implode("\n", $lines));
    }

    /**
     * @return list<ContentInterface>
     */
    public function platformContents(): array
    {
        return array_map(static fn (AttachmentContent $content): ContentInterface => match ($content->kind) {
            AttachmentContent::DOCUMENT => new Document($content->data, $content->mime),
            AttachmentContent::IMAGE    => new Image($content->data, $content->mime),
            default                     => new Text($content->data),
        }, $this->contents);
    }
}
