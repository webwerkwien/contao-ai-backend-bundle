<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Attachment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\Content\Document;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\Text;
use Webwerkwien\ContaoAiBackendBundle\Attachment\AttachmentContent;
use Webwerkwien\ContaoAiBackendBundle\Attachment\AttachmentReader;
use Webwerkwien\ContaoAiBackendBundle\Exception\AttachmentRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Support\BuildsAttachmentPolicy;

/**
 * Real bytes throughout: the 1×1 PNG and the small PDF are what finfo sees in
 * any other file, so the content check is measured, not assumed.
 */
class AttachmentReaderTest extends TestCase
{
    use BuildsAttachmentPolicy;

    public const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    public const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    private const BOTH = [AttachmentContent::DOCUMENT, AttachmentContent::IMAGE];

    private function reader(int $maxFileSize = 2048000): AttachmentReader
    {
        return new AttachmentReader($this->attachmentPolicy([
            'uploadTypes' => 'jpg,png,pdf,txt,csv,docx',
            'maxFileSize' => (string) $maxFileSize,
        ]));
    }

    /**
     * @return array{name: string, type: string, data: string}
     */
    private static function file(string $name, string $bytes): array
    {
        return ['name' => $name, 'type' => 'egal', 'data' => base64_encode($bytes)];
    }

    public function testNothingAttachedIsNothingToDo(): void
    {
        $accepted = $this->reader()->read(null, self::BOTH, 'm');

        self::assertSame([], $accepted->files);
        self::assertSame('Hallo', $accepted->turnText('Hallo'));
    }

    public function testAPdfAndAnImageArrive(): void
    {
        $accepted = $this->reader()->read([
            self::file('Angebot.pdf', self::PDF),
            self::file('foto.png', (string) base64_decode(self::PNG)),
        ], self::BOTH, 'm');

        self::assertSame(['Angebot.pdf', 'foto.png'], array_map(static fn ($f) => $f->name, $accepted->files));
        self::assertSame("Bitte lesen\n\nAnhang: Angebot.pdf\nAnhang: foto.png", $accepted->turnText('Bitte lesen'));
        self::assertSame("Anhang: Angebot.pdf\nAnhang: foto.png", $accepted->turnText(''), 'a file alone is a message');

        $contents = $accepted->platformContents();
        self::assertInstanceOf(Document::class, $contents[0]);
        self::assertInstanceOf(Image::class, $contents[1]);
    }

    public function testTextBecomesText(): void
    {
        $contents = $this->reader()->read([self::file('notiz.txt', 'Hallo Welt')], [], 'm')->platformContents();

        self::assertInstanceOf(Text::class, $contents[0]);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function refusals(): iterable
    {
        $pdf = self::file('a.pdf', self::PDF);

        yield 'six files'           => [array_fill(0, 6, $pdf), 'attachments_too_many'];
        yield 'not a list'          => [['name' => 'a.pdf'], 'attachment_invalid'];
        yield 'no data'             => [[['name' => 'a.pdf']], 'attachment_invalid'];
        yield 'broken base64'       => [[['name' => 'a.pdf', 'data' => '***']], 'attachment_invalid'];
        yield 'empty file'          => [[self::file('a.txt', '')], 'attachment_invalid'];
        yield 'twice the same name' => [[$pdf, self::file('A.PDF', self::PDF)], 'attachment_duplicate'];
        yield 'not in uploadTypes'  => [[self::file('a.exe', 'MZ')], 'attachment_type_not_allowed'];
        yield 'svg never'           => [[self::file('a.svg', '<svg/>')], 'attachment_type_not_allowed'];
        yield 'allowed, no reader'  => [[self::file('a.docx', 'PK')], 'attachment_unreadable'];
        yield 'renamed text as pdf' => [[self::file('a.pdf', 'nur Text')], 'attachment_mismatch'];
        yield 'png named jpg'       => [[self::file('a.jpg', (string) base64_decode(self::PNG))], 'attachment_mismatch'];
    }

    #[DataProvider('refusals')]
    public function testRefused(mixed $raw, string $label): void
    {
        try {
            $this->reader()->read($raw, self::BOTH, 'm');
            self::fail('accepted: ' . $label);
        } catch (AttachmentRefusedException $e) {
            self::assertSame($label, $e->labelKey);
        }
    }

    public function testExactlyMaxFileSizeIsAcceptedOneByteMoreIsNot(): void
    {
        $limit = \strlen(self::PDF) + 10;
        $exact = self::PDF . str_repeat(' ', 10);

        self::assertCount(1, $this->reader($limit)->read([self::file('a.pdf', $exact)], self::BOTH, 'm')->files);

        $this->expectExceptionObject(new AttachmentRefusedException('attachment_too_large'));
        $this->reader($limit)->read([self::file('a.pdf', $exact . ' ')], self::BOTH, 'm');
    }

    public function testAModelThatCannotReadPdfIsNamed(): void
    {
        try {
            $this->reader()->read([self::file('a.pdf', self::PDF)], [AttachmentContent::IMAGE], 'llama3');
            self::fail('accepted');
        } catch (AttachmentRefusedException $e) {
            self::assertSame('attachment_model_cannot_read', $e->labelKey);
            self::assertSame(['a.pdf', 'llama3'], $e->parameters);
        }
    }

    /**
     * @return iterable<string, array{0: mixed, 1: ?string}>
     */
    public static function names(): iterable
    {
        yield 'plain'             => ['Angebot 2026.pdf', 'Angebot 2026.pdf'];
        yield 'unix path'         => ['../../etc/passwd.pdf', 'passwd.pdf'];
        yield 'windows path'      => ['C:\\Users\\x\\a.pdf', 'a.pdf'];
        yield 'control character' => ["a\x00b\n.pdf", 'ab.pdf'];
        yield 'umlaut'            => ['Größe.pdf', 'Größe.pdf'];
        yield 'nothing left'      => ['../', null];
        yield 'not utf-8'         => ["\xFF.pdf", null];
        yield 'not a string'      => [42, null];
    }

    #[DataProvider('names')]
    public function testNamesAreCleaned(mixed $name, ?string $expected): void
    {
        self::assertSame($expected, AttachmentReader::cleanName($name));
    }
}
