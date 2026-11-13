<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\DataContainerAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\RecordPermissionChecker;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\ConsoleRunner;
use Webwerkwien\ContaoAiBackendBundle\Service\PendingActionStore;
use Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Support\BuildsFieldPermission;
use Webwerkwien\ContaoAiBackendBundle\Tool\CopyTool;

/**
 * `content_copy` and `article_copy`: the back end's copy and paste. DC_Table asks two
 * questions and no more — may the user copy the source (the clipboard's CreateAction
 * with the record), and may they create it at the target. The field rights apply when
 * the copy is changed afterwards, so a copy keeps fields the user may not edit.
 */
class CopyToolTest extends TestCase
{
    use BuildsFieldPermission;

    /** @var list<array<string, mixed>> */
    private array $runs = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $createVotes = [];

    private const ROWS = [
        'tl_content:40' => ['id' => 40, 'pid' => 7, 'ptable' => 'tl_article', 'sorting' => 128, 'type' => 'text', 'cssID' => 'a:2:{i:0;s:3:"faq";i:1;s:0:"";}', 'tstamp' => 5],
        'tl_content:44' => ['id' => 44, 'pid' => 12, 'ptable' => 'tl_news', 'sorting' => 64, 'type' => 'text'],
        'tl_article:7'  => ['id' => 7, 'pid' => 3, 'sorting' => 128, 'title' => 'Team', 'alias' => 'team', 'published' => '1', 'inColumn' => 'main', 'tstamp' => 5],
        'tl_article:8'  => ['id' => 8, 'pid' => 5, 'sorting' => 128, 'title' => 'Kontakt'],
        'tl_article:9'  => ['id' => 9, 'pid' => 4, 'sorting' => 128, 'title' => 'Ziel'],
        'tl_news:12'    => ['id' => 12, 'pid' => 2],
        'tl_page:3'     => ['id' => 3, 'pid' => 1],
        'tl_page:4'     => ['id' => 4, 'pid' => 1],
        'tl_page:5'     => ['id' => 5, 'pid' => 1],
    ];

    protected function setUp(): void
    {
        $this->runs = [];
        $this->createVotes = [];
    }

    protected function tearDown(): void
    {
        $this->restoreDca();
    }

    /**
     * @param string|null  $copyDenial    the clipboard's answer for the source
     * @param list<string> $deniedTargets "table:pid" where the voters refuse the create
     */
    private function tool(?string $copyDenial = null, array $deniedTargets = [], bool $coreHasCopy = true, bool $deniedNews = false, string $answer = '{"status":"ok","id":100,"children":0,"notCopied":[]}'): CopyTool
    {
        $console = $this->createMock(ConsoleRunner::class);
        $console->method('has')->willReturnCallback(static fn (string $name): bool => $coreHasCopy && \in_array($name, ['contao:content:copy', 'contao:article:copy'], true));
        $console->method('run')->willReturnCallback(function (array $input) use ($answer): array {
            $this->runs[] = $input;

            return [str_contains($answer, '"error"') ? 1 : 0, $answer];
        });

        $records = $this->createMock(RecordPermissionChecker::class);
        $records->method('recordCopyDenialReason')->willReturn($copyDenial);

        $dc = $this->createMock(DataContainerAccess::class);
        $dc->method('fetchRecord')->willReturnCallback(static fn (string $table, int $id): ?array => self::ROWS["$table:$id"] ?? null);
        $dc->method('isGranted')->willReturnCallback(static fn (ReadAction $a): bool => true);
        $dc->method('assertCreate')->willReturnCallback(function (string $table, array $new) use ($deniedTargets): void {
            if (\in_array($table . ':' . ($new['pid'] ?? ''), $deniedTargets, true)) {
                throw new ToolAccessDeniedException('Keine Rechte am Ziel.');
            }
            $this->createVotes[] = [$table, $new];
        });
        $dc->method('contentParentDenial')->willReturnCallback(static fn (array $record): ?string => $deniedNews && 'tl_news' === ($record['ptable'] ?? null) ? 'Keine Rechte auf News.' : null);

        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => match ($key) { 'isAdmin' => false, 'username' => 'redakteur', default => null });
        $user->method('__isset')->willReturnCallback(static fn (string $key): bool => \in_array($key, ['isAdmin', 'username'], true));

        $tool = new CopyTool($this->createMock(ToolAccessChecker::class), $this->createStub(TokenChecker::class), $console, $records);

        // No field right at all: a copy does not ask for one.
        $this->defineDca(['tl_content' => ['cssID' => ['inputType' => 'text', 'sql' => 'varchar(255) NOT NULL default \'\'']]]);
        $tool->setFieldPermission($this->fieldPermission([]));
        $tool->setAuthorizationChecker($this->grantingAuthorization([]));
        $tool->setPendingActionStore($this->createMock(PendingActionStore::class));
        $tool->setDataContainerAccess($dc);
        $tool->setActingUserOverride($user);

        return $tool;
    }

    public function testAnElementGoesDirectlyBehindItsSourceWithEveryField(): void
    {
        $output = $this->tool()->copyContent(40);

        self::assertStringContainsString('"id":100', $output);
        self::assertSame([['command' => 'contao:content:copy', 'id' => '40', '--operator' => 'redakteur']], $this->runs);

        // The voters judge the copy as it will be: the source's fields, cssID included,
        // at the source's parent — not the source itself.
        [$table, $new] = $this->createVotes[0];
        self::assertSame('tl_content', $table);
        self::assertSame([7, 'tl_article', 0, 'text'], [$new['pid'], $new['ptable'], $new['sorting'], $new['type']]);
        self::assertSame(self::ROWS['tl_content:40']['cssID'], $new['cssID']);
        self::assertArrayNotHasKey('id', $new);
    }

    public function testWithoutTheRightToCopyTheSourceNothingRuns(): void
    {
        try {
            $this->tool(copyDenial: 'Kein Zugriff auf tl_content:40.')->copyContent(40);
            self::fail('expected a denial');
        } catch (ToolAccessDeniedException $e) {
            self::assertStringContainsString('tl_content:40', $e->getMessage());
        }

        self::assertSame([], $this->runs);
    }

    public function testATargetTheVotersRefuseRunsNothing(): void
    {
        try {
            $this->tool(deniedTargets: ['tl_content:12'])->copyContent(40, 12, 'tl_news');
            self::fail('expected a denial');
        } catch (ToolAccessDeniedException) {
        }

        self::assertSame([], $this->runs);
    }

    public function testToAnotherParentNamesItAndItsTable(): void
    {
        $this->tool()->copyContent(40, 12, 'tl_news');

        self::assertSame(['command' => 'contao:content:copy', 'id' => '40', '--to' => '12', '--ptable' => 'tl_news', '--operator' => 'redakteur'], $this->runs[0]);
        self::assertSame([12, 'tl_news'], [$this->createVotes[0][1]['pid'], $this->createVotes[0][1]['ptable']]);
    }

    public function testToAnotherParentKeepsTheSourcesParentTable(): void
    {
        $this->tool()->copyContent(40, 9);

        self::assertSame(['command' => 'contao:content:copy', 'id' => '40', '--to' => '9', '--ptable' => 'tl_article', '--operator' => 'redakteur'], $this->runs[0]);
    }

    public function testAfterTakesTheParentOfThatElement(): void
    {
        $this->tool()->copyContent(40, 0, '', 44);

        self::assertSame(['command' => 'contao:content:copy', 'id' => '40', '--after' => '44', '--operator' => 'redakteur'], $this->runs[0]);
        self::assertSame([12, 'tl_news'], [$this->createVotes[0][1]['pid'], $this->createVotes[0][1]['ptable']]);
    }

    public function testTheEditorsParentRuleJudgesTheTarget(): void
    {
        try {
            $this->tool(deniedNews: true)->copyContent(40, 0, '', 44);
            self::fail('expected a denial');
        } catch (ToolAccessDeniedException $e) {
            self::assertStringContainsString('News', $e->getMessage());
        }

        self::assertSame([], $this->runs);
    }

    /**
     * @return iterable<string, array{0: int, 1: string, 2: int}>
     */
    public static function unclearTargets(): iterable
    {
        yield 'pid and after' => [9, '', 44];
        yield 'ptable without pid' => [0, 'tl_news', 0];
        yield 'ptable with after' => [0, 'tl_news', 44];
    }

    /**
     * @dataProvider unclearTargets
     */
    public function testAnUnclearTargetIsRefused(int $pid, string $ptable, int $after): void
    {
        try {
            $this->tool()->copyContent(40, $pid, $ptable, $after);
            self::fail('expected a refusal');
        } catch (ToolRefusedException) {
        }

        self::assertSame([], $this->runs);
    }

    public function testAMissingSourceIsSaid(): void
    {
        $this->expectException(ToolRefusedException::class);
        $this->expectExceptionMessage('999');

        $this->tool()->copyContent(999);
    }

    public function testTheCoresRefusalReachesTheChatAsOne(): void
    {
        $this->expectException(ToolRefusedException::class);
        $this->expectExceptionMessage('into itself');

        $this->tool(answer: '{"status":"error","message":"A tl_content record cannot be copied into itself or one of its own elements"}')->copyContent(40, 40, 'tl_content');
    }

    public function testAnOlderCoreDoesNotOfferTheTools(): void
    {
        self::assertFalse($this->tool(coreHasCopy: false)->isAccessibleBy($this->createMock(BackendUser::class)));
    }

    public function testAnArticleGoesDirectlyBehindItsSourceUnpublished(): void
    {
        $this->tool()->copyArticle(7);

        self::assertSame([['command' => 'contao:article:copy', 'id' => '7', '--operator' => 'redakteur']], $this->runs);

        // The copy the voters see is the one Contao writes: offline, without an alias.
        [$table, $new] = $this->createVotes[0];
        self::assertSame('tl_article', $table);
        self::assertSame([3, 0, 'Team', '', ''], [$new['pid'], $new['sorting'], $new['title'], $new['published'], $new['alias']]);
    }

    public function testAnArticleToAnotherPage(): void
    {
        $this->tool()->copyArticle(7, 4);

        self::assertSame(['command' => 'contao:article:copy', 'id' => '7', '--to' => '4', '--operator' => 'redakteur'], $this->runs[0]);
        self::assertSame(4, $this->createVotes[0][1]['pid']);
    }

    public function testAnArticleBehindAnother(): void
    {
        $this->tool()->copyArticle(7, 0, 8);

        self::assertSame(['command' => 'contao:article:copy', 'id' => '7', '--after' => '8', '--operator' => 'redakteur'], $this->runs[0]);
        self::assertSame(5, $this->createVotes[0][1]['pid']);
    }

    public function testAnArticleToAMissingPageIsRefused(): void
    {
        try {
            $this->tool()->copyArticle(7, 99);
            self::fail('expected a refusal');
        } catch (ToolRefusedException $e) {
            self::assertStringContainsString('99', $e->getMessage());
        }

        self::assertSame([], $this->runs);
    }

    public function testWithoutTheRightToCopyTheArticleNothingRuns(): void
    {
        try {
            $this->tool(copyDenial: 'Kein Zugriff auf tl_article:7.')->copyArticle(7);
            self::fail('expected a denial');
        } catch (ToolAccessDeniedException) {
        }

        self::assertSame([], $this->runs);
    }
}
