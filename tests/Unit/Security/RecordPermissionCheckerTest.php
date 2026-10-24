<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Security;

use Contao\BackendUser;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\DataContainerAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\RecordPermissionChecker;

/**
 * The macro tools ask Contao's data container voters (v0.18.0), admins included.
 *
 * Until v0.18.0 this class rebuilt the voters by hand and admins skipped it. Creating a
 * news archive, calendar or FAQ category was the `cud` voter alone, and until v0.10.0 a
 * denial fell back to the `newp`/`calp`/`faqp` arrays of Contao 5.3 to 5.6 — a second
 * door. The voters now decide both, including `cud`.
 */
class RecordPermissionCheckerTest extends TestCase
{
    /** @var list<CreateAction|ReadAction|UpdateAction|DeleteAction> */
    private array $asked = [];

    private function user(bool $isAdmin): BackendUser
    {
        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => 'isAdmin' === $key ? $isAdmin : null);

        return $user;
    }

    /**
     * @param array<string, mixed>|null $record what every lookup finds
     */
    private function checker(?array $record, bool $granted = true, ?string $contentDenial = null, bool $tableExists = true): RecordPermissionChecker
    {
        $access = $this->createMock(DataContainerAccess::class);
        $access->method('fetchRecord')->willReturn($record);
        $access->method('tableExists')->willReturn($tableExists);
        $access->method('denialReason')->willReturnCallback(function (CreateAction|ReadAction|UpdateAction|DeleteAction $action, ?int $id) use ($granted): ?string {
            $this->asked[] = $action;

            return $granted ? null : 'verweigert';
        });
        $access->method('assertCreate')->willReturnCallback(function (string $table, array $new) use ($granted): void {
            $this->asked[] = new CreateAction($table, $new);
            if (!$granted) {
                throw new ToolAccessDeniedException('verweigert');
            }
        });
        $access->method('contentParentDenial')->willReturn($contentDenial);

        return new RecordPermissionChecker($access);
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function operations(): iterable
    {
        yield 'read'   => ['read', ReadAction::class];
        yield 'edit'   => ['edit', UpdateAction::class];
        yield 'delete' => ['delete', DeleteAction::class];
    }

    /**
     * @dataProvider operations
     *
     * @param class-string $actionClass
     */
    public function testEachOperationAsksTheVotersWithTheStoredRow(string $operation, string $actionClass): void
    {
        $row = ['id' => 4, 'pid' => 1];
        $this->checker($row)->assertRecordAccess($this->user(false), 'tl_news', 4, $operation);

        self::assertCount(1, $this->asked);
        self::assertInstanceOf($actionClass, $this->asked[0]);
        self::assertSame($row, $this->asked[0]->getCurrent());
    }

    public function testAdminsAreAskedToo(): void
    {
        $this->expectException(ToolAccessDeniedException::class);

        $this->checker(['id' => 4], false)->assertRecordAccess($this->user(true), 'tl_page', 4, 'edit');
    }

    public function testAnUnsupportedTableIsRefusedWithoutAskingTheVoters(): void
    {
        self::assertNotNull($this->checker(['id' => 4])->recordAccessDenialReason($this->user(true), 'tl_user', 4, 'edit'));
        self::assertSame([], $this->asked);
    }

    public function testAMissingRecordIsAReasonForTheRecursivePaths(): void
    {
        self::assertSame('Datensatz 4 in tl_page nicht gefunden.', $this->checker(null)->recordAccessDenialReason($this->user(false), 'tl_page', 4, 'edit'));
    }

    public function testContentBelowAnUnjudgedParentIsRefusedForEditorsOnly(): void
    {
        $record = ['id' => 4, 'ptable' => 'tl_whatever', 'pid' => 1];

        self::assertSame('kein Elternteil', $this->checker($record, true, 'kein Elternteil')->recordAccessDenialReason($this->user(false), 'tl_content', 4, 'edit'));
        self::assertNull($this->checker($record, true, 'kein Elternteil')->recordAccessDenialReason($this->user(true), 'tl_content', 4, 'edit'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function containers(): iterable
    {
        yield 'news archive' => ['tl_news_archive'];
        yield 'calendar'     => ['tl_calendar'];
        yield 'faq category' => ['tl_faq_category'];
        yield 'page'         => ['tl_page'];
        yield 'form'         => ['tl_form'];
        yield 'channel'      => ['tl_newsletter_channel'];
        yield 'module'       => ['tl_module'];
        yield 'layout'       => ['tl_layout'];
    }

    /**
     * News, events, FAQ and newsletters are optional bundles. Without one, "record 4 not
     * found" would send the agent looking for a record instead of a bundle.
     */
    public function testAMissingBundleIsNamedAsSuch(): void
    {
        self::assertStringContainsString('nicht installiert', (string) $this->checker(null, tableExists: false)->recordAccessDenialReason($this->user(false), 'tl_newsletter_channel', 4, 'edit'));

        $this->expectException(ToolRefusedException::class);
        $this->expectExceptionMessage('nicht installiert');

        $this->checker(null, tableExists: false)->assertCanCreateContainer($this->user(false), 'tl_newsletter_channel', 4);
    }

    /**
     * The clone lands next to its source: DC_Table's paste asks with the source record
     * without its id and with a position — `sorting` null would read as a copy to the
     * clipboard, judged on the source instead of the parent.
     *
     * @dataProvider containers
     */
    public function testACloneAsksToCreateTheSourceAgainNextToIt(string $table): void
    {
        $this->checker(['id' => 4, 'pid' => 2, 'sorting' => 256, 'tstamp' => 1700000000, 'title' => 'Quelle'])
            ->assertCanCreateContainer($this->user(false), $table, 4);

        self::assertCount(1, $this->asked);
        self::assertInstanceOf(CreateAction::class, $this->asked[0]);
        self::assertSame($table, $this->asked[0]->getDataSource());
        self::assertSame(['pid' => 2, 'sorting' => 256, 'tstamp' => 0, 'title' => 'Quelle'], $this->asked[0]->getNew());
    }

    /**
     * @dataProvider containers
     */
    public function testACloneTheVotersRefuseIsDenied(string $table): void
    {
        $this->expectException(ToolAccessDeniedException::class);

        $this->checker(['id' => 4, 'pid' => 2], false)->assertCanCreateContainer($this->user(true), $table, 4);
    }

    public function testACloneOfAMissingSourceIsRefused(): void
    {
        $this->expectException(ToolRefusedException::class);

        $this->checker(null)->assertCanCreateContainer($this->user(false), 'tl_calendar', 4);
    }

    public function testOnlyContainersAreCloned(): void
    {
        $this->expectException(ToolAccessDeniedException::class);

        $this->checker(['id' => 4])->assertCanCreateContainer($this->user(true), 'tl_news', 4);
    }

    /**
     * A subpage of a recursive clone: the clipboard question — the record itself, id
     * kept, `sorting` null — which the page voter answers with the edit or hierarchy
     * right on that page.
     */
    public function testACopyAsksWithTheRecordAsTheClipboardDoes(): void
    {
        self::assertNull($this->checker(['id' => 9, 'pid' => 4, 'sorting' => 128, 'tstamp' => 1])->recordCopyDenialReason($this->user(false), 'tl_page', 9));

        self::assertInstanceOf(CreateAction::class, $this->asked[0]);
        self::assertSame(['id' => 9, 'pid' => 4, 'sorting' => null, 'tstamp' => null], $this->asked[0]->getNew());
    }
}
