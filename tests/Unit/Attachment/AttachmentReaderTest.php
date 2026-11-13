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
            'uploadTypes' => 'jpg,png,pdf,txt,csv,docx,zip,mov',
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
     * A type Contao allows for uploads but no converter reads (v1.3.0): accepted, so that
     * file_store can put it into the file manager; the model learns only its name.
     */
    public function testAnUnreadableAllowedFileArrivesWithItsNameOnly(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        $zip->addFromString('a.txt', 'a');
        $zip->close();
        $bytes = (string) file_get_contents($zipPath);
        unlink($zipPath);

        $accepted = $this->reader()->read([self::file('archiv.zip', $bytes)], self::BOTH, 'm', true);

        self::assertCount(1, $accepted->files);
        self::assertFalse($accepted->files[0]->readable);
        self::assertSame($bytes, $accepted->files[0]->bytes);
        self::assertSame([], $accepted->platformContents());
        self::assertSame("Bitte ablegen\n\nAnhang: archiv.zip (nicht lesbar, kann abgelegt werden)", $accepted->turnText('Bitte ablegen'));
    }

    /**
     * Without file_store the chat could do nothing with it: refused as before v1.3.0.
     */
    public function testAnUnreadableFileIsRefusedForAUserWhoCannotStoreIt(): void
    {
        $this->expectException(AttachmentRefusedException::class);
        $this->expectExceptionMessage('attachment_unreadable');
        $this->reader()->read([self::file('archiv.zip', 'PK')], self::BOTH, 'm');
    }

    public function testAnSvgBeginningWithACommentIsNoMismatch(): void
    {
        $reader = new AttachmentReader($this->attachmentPolicy(['uploadTypes' => 'svg', 'maxFileSize' => '2048000']));
        $accepted = $reader->read([self::file('logo.svg', '<!-- logo --><svg xmlns="http://www.w3.org/2000/svg"/>')], self::BOTH, 'm', true);

        self::assertCount(1, $accepted->files);
    }

    public function testShortTagBytesInBinaryDataAreNoScript(): void
    {
        // A zip or a video holds "<?=" or "#!" by chance; a script has no NUL byte.
        self::assertFalse(AttachmentReader::looksLikeAScript("PK\x03\x04\0\0<?= x"));
        self::assertFalse(AttachmentReader::looksLikeAScript("\0#!/bin/sh"));
        self::assertTrue(AttachmentReader::looksLikeAScript("PK\x03\x04\0\0<?php echo 1;"));
        self::assertTrue(AttachmentReader::looksLikeAScript("\u{FEFF}\n#!/bin/sh"));
        self::assertTrue(AttachmentReader::looksLikeAScript('<p><?= $x ?></p>'));
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
        // lenient decoding would skip the foreign characters and read a valid PDF
        yield 'base64 with a stray character' => [[['name' => 'a.pdf', 'data' => substr(base64_encode(self::PDF), 0, 8) . '*' . substr(base64_encode(self::PDF), 8)]], 'attachment_invalid'];
        yield 'base64 as a data url' => [[['name' => 'a.pdf', 'data' => 'data:application/pdf;base64,' . base64_encode(self::PDF)]], 'attachment_invalid'];
        yield 'same name composed and decomposed' => [[self::file("Gr\u{00F6}\u{00DF}e.pdf", self::PDF), self::file("Gro\u{0308}\u{00DF}e.pdf", self::PDF)], 'attachment_duplicate'];
        yield 'empty file'          => [[self::file('a.txt', '')], 'attachment_invalid'];
        yield 'twice the same name' => [[$pdf, self::file('A.PDF', self::PDF)], 'attachment_duplicate'];
        yield 'not in uploadTypes'  => [[self::file('a.exe', 'MZ')], 'attachment_type_not_allowed'];
        yield 'svg never'           => [[self::file('a.svg', '<svg/>')], 'attachment_type_not_allowed'];
        yield 'no reader, text named docx' => [[self::file('a.docx', 'nur Text')], 'attachment_mismatch'];
        yield 'no reader, unknown type, a script' => [[self::file('a.mov', "<?php system(\$_GET['c']);")], 'attachment_mismatch'];
        yield 'no reader, script after a line break' => [[self::file('a.mov', "\n<?php system(\$_GET['c']);")], 'attachment_mismatch'];
        yield 'no reader, short echo tag' => [[self::file('a.mov', "<?= \$_GET['c'] ?>")], 'attachment_mismatch'];
        yield 'no reader, script in html' => [[self::file('a.mov', '<html><?PHP echo 1; ?></html>')], 'attachment_mismatch'];
        yield 'no reader, shebang' => [[self::file('a.mov', "#!/usr/bin/env python\nprint(1)")], 'attachment_mismatch'];
        yield 'renamed text as pdf' => [[self::file('a.pdf', 'nur Text')], 'attachment_mismatch'];
        yield 'png named jpg'       => [[self::file('a.jpg', (string) base64_decode(self::PNG))], 'attachment_mismatch'];
    }

    #[DataProvider('refusals')]
    public function testRefused(mixed $raw, string $label): void
    {
        try {
            // As for a user who may store files: the unreadable ones are checked, not refused outright.
            $this->reader()->read($raw, self::BOTH, 'm', true);
            self::fail('accepted: ' . $label);
        } catch (AttachmentRefusedException $e) {
            self::assertSame($label, $e->labelKey);
        }
    }

    /**
     * Too large is judged on the base64 length, before anything is decoded: a long
     * string that is not even base64 is refused as too large, not as broken.
     */
    public function testTheSizeIsKnownBeforeDecoding(): void
    {
        $this->expectExceptionObject(new AttachmentRefusedException('attachment_too_large'));
        $this->reader(100)->read([['name' => 'a.pdf', 'data' => str_repeat('*', 200)]], self::BOTH, 'm');
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
        // macOS hands over decomposed umlauts; the model types composed ones
        yield 'decomposed umlaut' => ["Gro\u{0308}\u{00DF}e.pdf", "Gr\u{00F6}\u{00DF}e.pdf"];
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
