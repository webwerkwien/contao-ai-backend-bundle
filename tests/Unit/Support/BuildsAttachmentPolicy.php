<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Support;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Webwerkwien\ContaoAiBackendBundle\Attachment\AttachmentPolicy;
use Webwerkwien\ContaoAiBackendBundle\Attachment\Converter\ImageConverter;
use Webwerkwien\ContaoAiBackendBundle\Attachment\Converter\PdfConverter;
use Webwerkwien\ContaoAiBackendBundle\Attachment\Converter\TextConverter;

/**
 * An AttachmentPolicy over given Contao settings and the three free converters.
 */
trait BuildsAttachmentPolicy
{
    /**
     * @param array<string, mixed> $settings Contao's localconfig values
     */
    private function attachmentPolicy(array $settings): AttachmentPolicy
    {
        $config = $this->getMockBuilder(Adapter::class)->disableOriginalConstructor()->addMethods(['get'])->getMock();
        $config->method('get')->willReturnCallback(static fn (string $key): mixed => $settings[$key] ?? null);
        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($config);

        return new AttachmentPolicy($framework, [new PdfConverter(), new ImageConverter(), new TextConverter()]);
    }
}
