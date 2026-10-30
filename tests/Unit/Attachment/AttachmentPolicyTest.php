<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Attachment;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Attachment\AttachmentConverterInterface;
use Webwerkwien\ContaoAiBackendBundle\Attachment\AttachmentPolicy;
use Webwerkwien\ContaoAiBackendBundle\Attachment\ChatAttachment;
use Webwerkwien\ContaoAiBackendBundle\Attachment\Converter\PdfConverter;
use Webwerkwien\ContaoAiBackendBundle\Attachment\Converter\TextConverter;
use Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Support\BuildsAttachmentPolicy;

class AttachmentPolicyTest extends TestCase
{
    use BuildsAttachmentPolicy;

    public function testReadableIsWhatContaoAllowsAndAConverterReads(): void
    {
        $policy = $this->attachmentPolicy(['uploadTypes' => 'JPG, pdf,docx,svg,zip,txt']);

        self::assertSame(['jpg', 'pdf', 'docx', 'svg', 'zip', 'txt'], $policy->uploadTypes());
        self::assertSame(['jpg', 'pdf', 'txt'], $policy->readableTypes(), 'docx needs the add-on; svg and zip nobody reads');
    }

    public function testNoConverterForAnUnknownExtension(): void
    {
        self::assertNull($this->attachmentPolicy([])->converterFor('docx'));
        self::assertInstanceOf(PdfConverter::class, $this->attachmentPolicy([])->converterFor('pdf'));
    }

    public function testTheFirstConverterForAnExtensionWins(): void
    {
        // Symfony hands tagged services over sorted by `priority`, highest first: an
        // add-on takes over csv with a priority above 0 (stable surface since 1.0),
        // e.g. to accept long lists that the free TextConverter refuses.
        $own = new class implements AttachmentConverterInterface {
            public function extensions(): array { return ['csv']; }
            public function acceptsMime(string $extension, string $mime): bool { return true; }
            public function convert(ChatAttachment $file): array { return []; }
        };
        $policy = new AttachmentPolicy($this->createMock(ContaoFramework::class), [$own, new TextConverter()]);

        self::assertSame($own, $policy->converterFor('csv'));
        self::assertInstanceOf(TextConverter::class, $policy->converterFor('txt'), 'the rest stays with the free converter');
    }

    public function testMaxFileSizeFallsBackToContaosDefault(): void
    {
        self::assertSame(2048000, $this->attachmentPolicy([])->maxFileSize());
        self::assertSame(500, $this->attachmentPolicy(['maxFileSize' => '500'])->maxFileSize());
        self::assertSame(AttachmentPolicy::MAX_FILE_SIZE, $this->attachmentPolicy(['maxFileSize' => '500000000'])->maxFileSize(), 'a high maxFileSize stops at the chat\'s own limit');
    }

    public function testIniSizes(): void
    {
        self::assertSame(8 * 1024 * 1024, AttachmentPolicy::iniBytes('8M'));
        self::assertSame(2 * 1024 ** 3, AttachmentPolicy::iniBytes('2g'));
        self::assertSame(512 * 1024, AttachmentPolicy::iniBytes('512K'));
        self::assertSame(1000, AttachmentPolicy::iniBytes('1000'));
        self::assertSame(0, AttachmentPolicy::iniBytes('0'));
    }
}
