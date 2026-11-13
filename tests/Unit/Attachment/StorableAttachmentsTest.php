<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Attachment;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\PlatformInterface;
use Webwerkwien\ContaoAiBackendBundle\Service\AgentInvocation;
use Webwerkwien\ContaoAiBackendBundle\Service\ChatViewContext;
use Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Support\BuildsAttachmentPolicy;

/**
 * Unreadable attachments only for a user who may store files (v1.3.0): the file picker
 * and the stream controller both decide from the tools the user may call.
 */
class StorableAttachmentsTest extends TestCase
{
    use BuildsAttachmentPolicy;

    public function testThePickerOffersEveryUploadTypeOnlyWithFileStore(): void
    {
        $policy = $this->attachmentPolicy(['uploadTypes' => 'jpg,pdf,zip,docx', 'maxFileSize' => '2048000']);

        self::assertSame(['jpg', 'pdf', 'zip', 'docx'], ChatViewContext::pickerTypes(['content_read', 'file_store'], $policy));
        self::assertSame(['jpg', 'pdf'], ChatViewContext::pickerTypes(['content_read', 'file_usages'], $policy));
    }

    public function testARunMayStoreFilesOnlyWithFileStore(): void
    {
        $agent = new Agent($this->createStub(PlatformInterface::class), 'm');

        self::assertTrue((new AgentInvocation($agent, 's', 'm', ['file_store'], ['file_store']))->mayStoreFiles());
        self::assertFalse((new AgentInvocation($agent, 's', 'm', ['file_usages'], ['file_store', 'file_usages']))->mayStoreFiles());
    }
}
