<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Attachment;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Webwerkwien\ContaoAiBackendBundle\Attachment\AttachmentContent;
use Webwerkwien\ContaoAiBackendBundle\Attachment\AttachmentConverterInterface;
use Webwerkwien\ContaoAiBackendBundle\Attachment\ChatAttachment;
use Webwerkwien\ContaoAiBackendBundle\Attachment\Converter\ImageConverter;
use Webwerkwien\ContaoAiBackendBundle\Attachment\Converter\PdfConverter;
use Webwerkwien\ContaoAiBackendBundle\Attachment\Converter\TextConverter;
use Webwerkwien\ContaoAiBackendBundle\Exception\AttachmentRefusedException;

class ConvertersTest extends TestCase
{
    public function testTheTagNameIsStableAndAttached(): void
    {
        // Part of the stable surface: add-ons tag their converters with this name,
        // and autoconfigure does it for them through the attribute.
        $attributes = (new \ReflectionClass(AttachmentConverterInterface::class))->getAttributes(AutoconfigureTag::class);

        self::assertCount(1, $attributes);
        self::assertSame('contao_ai_backend.attachment_converter', $attributes[0]->getArguments()[0] ?? null);
    }

    public function testPdf(): void
    {
        $converter = new PdfConverter();

        self::assertSame(['pdf'], $converter->extensions());
        self::assertTrue($converter->acceptsMime('pdf', 'application/pdf'));
        self::assertFalse($converter->acceptsMime('pdf', 'text/plain'), 'a renamed text file');

        [$content] = $converter->convert(new ChatAttachment('a.pdf', 'pdf', 'application/pdf', '%PDF-1.4'));
        self::assertSame(AttachmentContent::DOCUMENT, $content->kind);
        self::assertSame('%PDF-1.4', $content->data);
    }

    public function testImagesAcceptOnlyTheirOwnType(): void
    {
        $converter = new ImageConverter();

        self::assertSame(['jpg', 'jpeg', 'png', 'gif', 'webp'], $converter->extensions());
        self::assertNotContains('svg', $converter->extensions(), 'SVG can carry scripts, and models do not read it');
        self::assertTrue($converter->acceptsMime('jpg', 'image/jpeg'));
        self::assertFalse($converter->acceptsMime('png', 'image/jpeg'), 'a JPEG named .png');

        [$content] = $converter->convert(new ChatAttachment('a.png', 'png', 'image/png', 'PNG'));
        self::assertSame(AttachmentContent::IMAGE, $content->kind);
        self::assertSame('image/png', $content->mime);
    }

    public function testTextCarriesTheNameAndBecomesUtf8(): void
    {
        $converter = new TextConverter();

        self::assertTrue($converter->acceptsMime('csv', 'text/csv'));
        self::assertTrue($converter->acceptsMime('md', 'text/plain'));
        self::assertFalse($converter->acceptsMime('txt', 'application/pdf'));

        // "Größe" in Windows-1252, as Excel writes a CSV
        [$content] = $converter->convert(new ChatAttachment('liste.csv', 'csv', 'text/plain', "Gr\xF6\xDFe;1"));
        self::assertSame(AttachmentContent::TEXT, $content->kind);
        self::assertStringContainsString('liste.csv', $content->data);
        self::assertStringContainsString('Größe;1', $content->data);
    }

    public function testTextDropsTheByteOrderMark(): void
    {
        [$content] = (new TextConverter())->convert(new ChatAttachment('a.txt', 'txt', 'text/plain', "\xEF\xBB\xBFHallo"));

        self::assertStringContainsString("\n\nHallo", $content->data);
    }

    public function testExactlyMaxCharsIsReadOneMoreIsRefused(): void
    {
        $converter = new TextConverter();

        self::assertCount(1, $converter->convert(new ChatAttachment('a.txt', 'txt', 'text/plain', str_repeat('ä', TextConverter::MAX_CHARS))), 'characters, not bytes');

        try {
            $converter->convert(new ChatAttachment('a.txt', 'txt', 'text/plain', str_repeat('a', TextConverter::MAX_CHARS + 1)));
            self::fail('a text over the limit was read');
        } catch (AttachmentRefusedException $e) {
            self::assertSame('attachment_too_long', $e->labelKey);
            self::assertSame(['a.txt', TextConverter::MAX_CHARS + 1, TextConverter::MAX_CHARS], $e->parameters);
        }
    }
}
