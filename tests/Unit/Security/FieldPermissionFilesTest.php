<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Security;

use Contao\BackendUser;
use Contao\StringUtil;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Security\FieldPermission;
use Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Support\BuildsFieldPermission;

/**
 * File fields take files from the user's file mounts (#58).
 *
 * Contao checks the mounts in the file picker, not when a record is saved; the chat has
 * no picker. Before this check an editor whose only mount was one folder could set a
 * content element's image to any file whose UUID they knew.
 */
class FieldPermissionFilesTest extends TestCase
{
    use BuildsFieldPermission;

    private const INSIDE  = '11111111-1111-1111-1111-111111111111';
    private const OUTSIDE = '22222222-2222-2222-2222-222222222222';
    private const MISSING = '33333333-3333-3333-3333-333333333333';
    private const DEEPER  = '44444444-4444-4444-4444-444444444444';

    private const FILES = [
        self::INSIDE  => 'files/mine/photo.jpg',
        self::OUTSIDE => 'files/protected/contract.pdf',
        self::DEEPER  => 'files/mine/sub/folder/image.png',
    ];

    protected function setUp(): void
    {
        $this->defineDca([
            'tl_content' => [
                'headline'  => ['inputType' => 'text'],
                'singleSRC' => ['inputType' => 'fileTree', 'eval' => ['filesOnly' => true]],
                'multiSRC'  => ['inputType' => 'fileTree', 'eval' => ['multiple' => true]],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreDca();
    }

    private function permission(): FieldPermission
    {
        return $this->fieldPermission([], self::FILES, ['files/mine']);
    }

    private function user(bool $admin = false): BackendUser
    {
        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => 'isAdmin' === $key ? $admin : null);

        return $user;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function refusal(array $values): string
    {
        try {
            $this->permission()->assertFilesReachable($this->user(), 'tl_content', $values);
        } catch (ToolAccessDeniedException $e) {
            return $e->getMessage();
        }

        $this->fail('No refusal for ' . json_encode(array_map(static fn ($v) => \is_string($v) ? bin2hex($v) : $v, $values)));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function reachableValues(): iterable
    {
        yield 'a file in the mount'          => [self::INSIDE];
        yield 'a file below the mount'       => [self::DEEPER];
        yield 'upper case and spaces'        => ['  ' . strtoupper(self::INSIDE) . ' '];
        yield 'cleared'                      => [''];
        yield 'not sent'                     => [null];
    }

    /**
     * @dataProvider reachableValues
     */
    public function testAFileInTheMountsOrNoneIsAccepted(mixed $value): void
    {
        $this->permission()->assertFilesReachable($this->user(), 'tl_content', ['singleSRC' => $value]);

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function listsInTheMounts(): iterable
    {
        yield 'JSON list'       => ['["' . self::INSIDE . '","' . self::DEEPER . '"]'];
        yield 'comma list'      => [self::INSIDE . ', ' . self::DEEPER];
        yield 'array'           => [[self::INSIDE, self::DEEPER]];
        yield 'empty JSON list' => ['[]'];
    }

    /**
     * @dataProvider listsInTheMounts
     */
    public function testAListOfFilesInTheMountsIsAccepted(mixed $value): void
    {
        $this->permission()->assertFilesReachable($this->user(), 'tl_content', ['multiSRC' => $value]);

        $this->addToAssertionCount(1);
    }

    /**
     * The measured case: a file outside the editor's only mount.
     */
    public function testAFileOutsideTheMountsIsRefused(): void
    {
        $this->assertStringContainsString('tl_content.singleSRC', $this->refusal(['singleSRC' => self::OUTSIDE]));
    }

    /**
     * Unknown, outside and not a UUID read the same: the answer must not tell which
     * files exist beyond the mounts.
     */
    public function testUnknownOutsideAndMalformedGetTheSameAnswer(): void
    {
        $outside = $this->refusal(['singleSRC' => self::OUTSIDE]);

        $this->assertSame($outside, $this->refusal(['singleSRC' => self::MISSING]));
        $this->assertSame($outside, $this->refusal(['singleSRC' => 'files/protected/contract.pdf']));
        $this->assertStringNotContainsString('protected', $outside);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function listsWithOneFileOutside(): iterable
    {
        yield 'JSON list'  => ['["' . self::INSIDE . '","' . self::OUTSIDE . '"]'];
        yield 'comma list' => [self::INSIDE . ',' . self::OUTSIDE];
        yield 'array'      => [[self::INSIDE, self::OUTSIDE]];
        yield 'nested'     => [[self::INSIDE, [self::INSIDE]]];
    }

    /**
     * @dataProvider listsWithOneFileOutside
     */
    public function testOneFileOutsideRefusesTheList(mixed $value): void
    {
        $this->assertStringContainsString('tl_content.multiSRC', $this->refusal(['multiSRC' => $value]));
    }

    /**
     * The core also takes the stored forms, a binary UUID and the serialized list.
     */
    public function testTheStoredFormsAreCheckedToo(): void
    {
        $binary = StringUtil::uuidToBin(self::OUTSIDE);

        $this->assertStringContainsString('tl_content.singleSRC', $this->refusal(['singleSRC' => $binary]));
        $this->assertStringContainsString('tl_content.multiSRC', $this->refusal(['multiSRC' => serialize([StringUtil::uuidToBin(self::INSIDE), $binary])]));

        $this->permission()->assertFilesReachable($this->user(), 'tl_content', ['singleSRC' => StringUtil::uuidToBin(self::INSIDE)]);
    }

    public function testEveryRefusedFieldIsNamed(): void
    {
        $message = $this->refusal(['singleSRC' => self::OUTSIDE, 'multiSRC' => self::MISSING, 'headline' => 'Neu']);

        $this->assertStringContainsString('tl_content.singleSRC, tl_content.multiSRC', $message);
        $this->assertStringNotContainsString('headline', $message);
    }

    public function testAFieldThatIsNoFileFieldIsNotChecked(): void
    {
        $this->permission()->assertFilesReachable($this->user(), 'tl_content', ['headline' => self::OUTSIDE]);

        $this->addToAssertionCount(1);
    }

    public function testAnAdminReachesEveryFile(): void
    {
        $this->permission()->assertFilesReachable($this->user(true), 'tl_content', ['singleSRC' => self::OUTSIDE, 'multiSRC' => self::MISSING]);

        $this->addToAssertionCount(1);
    }

    /**
     * A create sets its values too; the file check goes with the field rights.
     */
    public function testACreateIsCheckedLikeAnUpdate(): void
    {
        $permission = $this->fieldPermission(['tl_content::singleSRC'], self::FILES, ['files/mine']);

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessage('tl_content.singleSRC');

        $permission->assertCreatable($this->user(), 'tl_content', ['singleSRC' => self::OUTSIDE]);
    }
}
