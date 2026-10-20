<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
use Webwerkwien\ContaoAiBackendBundle\Security\DataContainerAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\TableAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\ConsoleRunner;
use Webwerkwien\ContaoAiBackendBundle\Tool\ContentFindTool;

/**
 * content_find (v0.20.0): the core's contao:content:find, every hit voted on as a read of
 * the element. What the user may not read is left out and counted.
 */
class ContentFindToolTest extends TestCase
{
    /** @var list<array<string, string>> */
    private array $inputs = [];

    private const ANSWER = ['status' => 'ok', 'query' => 'CMS', 'count' => 3, 'more' => false, 'results' => [
        ['id' => 160, 'type' => 'text', 'root' => ['id' => 2, 'language' => 'en']],
        ['id' => 416, 'type' => 'text', 'root' => ['id' => 1, 'language' => 'de']],
        ['id' => 999, 'type' => 'text', 'root' => ['id' => 9, 'language' => 'de']],
    ]];

    /**
     * @param list<int>                 $readable     element ids the voters let the user read
     * @param list<int>                 $parentDenied element ids whose parent table refuses an editor
     * @param array<string, mixed>|null $answer       what the core answers, else ANSWER
     */
    private function tool(array $readable, bool $hasCommand = true, bool $module = true, bool $admin = false, array $parentDenied = [], ?array $answer = null): ContentFindTool
    {
        $console = $this->createMock(ConsoleRunner::class);
        $console->method('has')->willReturn($hasCommand);
        $console->method('run')->willReturnCallback(function (array $input) use ($answer): array {
            $this->inputs[] = $input;

            return [0, (string) json_encode($answer ?? self::ANSWER)];
        });

        $tables = $this->createMock(TableAccess::class);
        $tables->method('canAccess')->willReturn($module);

        $access = $this->createMock(ToolAccessChecker::class);
        $access->method('canUseTool')->willReturn(true);

        $records = $this->createMock(DataContainerAccess::class);
        $records->method('fetchRecord')->willReturnCallback(static fn (string $table, int $id): array => ['id' => $id, 'pid' => 1, 'ptable' => 'tl_article']);
        $records->method('isGranted')->willReturnCallback(static fn (ReadAction $action): bool => \in_array((int) $action->getCurrentId(), $readable, true));
        $records->method('contentParentDenial')->willReturnCallback(static fn (array $record): ?string => \in_array((int) $record['id'], $parentDenied, true) ? 'Kein Zugriff auf die Eltern-Tabelle.' : null);

        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => match ($key) {
            'isAdmin'  => $admin,
            'username' => 'redakteur',
            default    => null,
        });

        $tool = new ContentFindTool($access, $this->createStub(TokenChecker::class), $console, $tables);
        $tool->setDataContainerAccess($records);
        $tool->setActingUserOverride($user);

        return $tool;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $answer): array
    {
        self::assertMatchesRegularExpression('#^<tool_output_data tool="content_find">\n#', $answer);

        return json_decode((string) preg_replace('#^<tool_output_data[^>]*>\n|\n</tool_output_data>$#', '', $answer), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testOnlyWhatTheUserMayReadComesBack(): void
    {
        $answer = self::decode($this->tool([160, 416])->find('CMS'));

        self::assertSame([160, 416], array_column($answer['results'], 'id'));
        self::assertSame(2, $answer['count']);
        self::assertSame(1, $answer['hidden']);
        self::assertStringContainsString('1 further element', $answer['note']);
        self::assertStringNotContainsString('999', (string) json_encode($answer), 'a hidden element is counted, not named');
    }

    /**
     * As content_read: for an editor the parent table counts too; an admin is not asked.
     */
    public function testAnElementWhoseParentRefusesAnEditorIsHidden(): void
    {
        $answer = self::decode($this->tool([160, 416, 999], parentDenied: [416])->find('CMS'));

        self::assertSame([160, 999], array_column($answer['results'], 'id'));
        self::assertSame(1, $answer['hidden']);

        $admin = self::decode($this->tool([160, 416, 999], admin: true, parentDenied: [416])->find('CMS'));
        self::assertSame(3, $admin['count']);
    }

    /**
     * An answer without its result list is refused, not passed on unfiltered.
     */
    public function testAnAnswerWithoutResultsFailsClosed(): void
    {
        $this->expectException(ToolExecutionException::class);

        $this->tool([160], answer: ['status' => 'ok', 'hits' => [['id' => 999]]])->find('CMS');
    }

    public function testNothingHiddenSaysNothingAboutIt(): void
    {
        $answer = self::decode($this->tool([160, 416, 999])->find('CMS'));

        self::assertSame(3, $answer['count']);
        self::assertArrayNotHasKey('hidden', $answer);
    }

    public function testRootAndLimitReachTheCommand(): void
    {
        $this->tool([160])->find('powerful', 2, 80);

        self::assertSame(['command' => 'contao:content:find', 'query' => 'powerful', '--limit' => '50', '--root' => '2'], $this->inputs[0]);
    }

    public function testWithoutTheArticleModuleNothingIsSearched(): void
    {
        $tool = $this->tool([160], module: false);

        try {
            $tool->find('CMS');
            self::fail('expected a denial');
        } catch (ToolAccessDeniedException) {
        }

        self::assertSame([], $this->inputs);
    }

    /**
     * core v1.10.0 brought the command; before it the tool is not offered.
     */
    public function testAnOlderCoreDoesNotOfferIt(): void
    {
        $user = $this->createMock(BackendUser::class);

        self::assertFalse($this->tool([], hasCommand: false)->isAccessibleBy($user));
        self::assertTrue($this->tool([])->isAccessibleBy($user));
    }

    /**
     * v0.21.0: the linked versions core v1.11.0 names per hit, and the roots, go through
     * the voters as well — a version outside the user's mounts does not reach the model.
     */
    public function testLinkedVersionsAndRootsTheUserMayNotReadAreLeftOut(): void
    {
        $answer = ['status' => 'ok', 'query' => 'CMS', 'count' => 1, 'more' => false, 'results' => [
            ['id' => 160, 'type' => 'text', 'root' => ['id' => 1, 'language' => 'en'], 'versions' => [
                'page'    => [['id' => 77, 'title' => 'Startseite', 'language' => 'de'], ['id' => 51, 'title' => 'Accueil secret', 'language' => 'fr']],
                'article' => [['id' => 41, 'title' => 'Slider', 'language' => 'de']],
                'unknown' => [['id' => 999, 'title' => 'Never passed on']],
            ]],
        ], 'roots' => [['id' => 1, 'title' => 'EN', 'language' => 'en'], ['id' => 9, 'title' => 'Intern', 'language' => 'fr']]];

        $decoded = self::decode($this->tool([160, 77, 41, 1], answer: $answer)->find('CMS'));
        $hit = $decoded['results'][0];

        self::assertSame([77], array_column($hit['versions']['page'], 'id'));
        self::assertSame([41], array_column($hit['versions']['article'], 'id'));
        self::assertArrayNotHasKey('unknown', $hit['versions']);
        self::assertSame(1, $hit['hiddenVersions']);
        self::assertSame([1], array_column($decoded['roots'], 'id'));
        self::assertStringNotContainsString('secret', json_encode($decoded, JSON_THROW_ON_ERROR));
    }
}
