<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Attachment;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Attachment\AttachmentContent;
use Webwerkwien\ContaoAiBackendBundle\Attachment\ChatAttachment;
use Webwerkwien\ContaoAiBackendBundle\Exception\AttachmentRefusedException;

class ValueTypesTest extends TestCase
{
    public function testAnAttachmentKnowsItsSize(): void
    {
        self::assertSame(3, (new ChatAttachment('a.txt', 'txt', 'text/plain', 'abc'))->size());
    }

    public function testContentKinds(): void
    {
        self::assertSame(AttachmentContent::TEXT, AttachmentContent::text('x')->kind);
        self::assertSame('application/pdf', AttachmentContent::document('%PDF')->mime);
        self::assertSame(AttachmentContent::IMAGE, AttachmentContent::image('png', 'image/png')->kind);
    }

    public function testARefusalCarriesItsLabel(): void
    {
        $e = new AttachmentRefusedException('attachment_mismatch', ['a.pdf']);

        self::assertSame('attachment_mismatch', $e->labelKey);
        self::assertSame(['a.pdf'], $e->parameters);
    }
}
