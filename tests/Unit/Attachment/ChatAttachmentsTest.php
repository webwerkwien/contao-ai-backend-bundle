<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Attachment;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Attachment\ChatAttachment;
use Webwerkwien\ContaoAiBackendBundle\Attachment\ChatAttachments;

class ChatAttachmentsTest extends TestCase
{
    public function testFoundByNameAndGoneAfterTheRequest(): void
    {
        $store = new ChatAttachments();
        $store->set([new ChatAttachment('Foto.png', 'png', 'image/png', 'x'), new ChatAttachment('Größe.txt', 'txt', 'text/plain', 'y')]);

        self::assertSame('Foto.png', $store->get('foto.png')?->name, 'the model may not repeat the case');
        self::assertSame('Größe.txt', $store->get('GRÖßE.TXT')?->name, 'umlauts too, not only ASCII');
        self::assertSame('Größe.txt', $store->get("Gro\u{0308}\u{00DF}e.txt")?->name, 'a decomposed umlaut finds the composed name');
        self::assertNull($store->get('anderes.png'));
        self::assertCount(2, $store->all());

        // A worker serves many requests: Symfony calls reset() between them
        // (ResetInterface, autoconfigured as kernel.reset).
        $store->reset();
        self::assertSame([], $store->all());
    }
}
