<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\DataContainerAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\TableAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\ConsoleRunner;
use Webwerkwien\ContaoAiBackendBundle\Tool\LanguageVersionsTool;

/**
 * language_versions (v0.21.0): core v1.11.0's contao:language:versions, with every version
 * and root voted on as a read; what the user may not read is left out and counted.
 */
class LanguageVersionsToolTest extends TestCase
{
    /** @var list<array<string, string>> */
    private array $inputs = [];

    private const ANSWER = [
        'status' => 'ok', 'table' => 'tl_article',
        'record' => ['id' => 36, 'title' => 'Login', 'published' => true, 'language' => 'en', 'root' => 1],
        'linked' => true, 'main' => 36,
        'versions' => [
            ['id' => 102, 'title' => 'Anmeldung', 'published' => true, 'language' => 'de', 'root' => 76, 'main' => false],
            ['id' => 555, 'title' => 'Secret', 'published' => true, 'language' => 'fr', 'root' => 50, 'main' => false],
        ],
        'otherRoots' => [['id' => 76, 'title' => 'DE', 'language' => 'de'], ['id' => 50, 'title' => 'FR intern', 'language' => 'fr']],
    ];

    /**
     * @param list<string>              $readable "table:id" the voters let the user read
     * @param array<string, mixed>|null $answer   what the core answers, else ANSWER
     */
    private function tool(array $readable, bool $hasCommand = true, bool $module = true, ?array $answer = null): LanguageVersionsTool
    {
        $console = $this->createMock(ConsoleRunner::class);
        $console->method('has')->willReturnCallback(static fn (string $name): bool => $hasCommand && 'contao:language:versions' === $name);
        $console->method('run')->willReturnCallback(function (array $input) use ($answer): array {
            $this->inputs[] = $input;

            return [0, (string) json_encode($answer ?? self::ANSWER)];
        });

        $tables = $this->createMock(TableAccess::class);
        $tables->method('canAccess')->willReturn($module);

        $access = $this->createMock(ToolAccessChecker::class);
        $access->method('canUseTool')->willReturn(true);

        $records = $this->createMock(DataContainerAccess::class);
        $records->method('fetchRecord')->willReturnCallback(static fn (string $table, int $id): array => ['id' => $id, 'table' => $table]);
        $records->method('isGranted')->willReturnCallback(static fn (ReadAction $action): bool => \in_array($action->getDataSource() . ':' . $action->getCurrentId(), $readable, true));

        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => match ($key) {
            'isAdmin'  => false,
            'username' => 'redakteur',
            default    => null,
        });

        $tool = new LanguageVersionsTool($access, $this->createStub(TokenChecker::class), $console, $tables);
        $tool->setDataContainerAccess($records);
        $tool->setActingUserOverride($user);

        return $tool;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $answer): array
    {
        self::assertMatchesRegularExpression('#^<tool_output_data tool="language_versions">\n#', $answer);

        return json_decode((string) preg_replace('#^<tool_output_data[^>]*>\n|\n</tool_output_data>$#', '', $answer), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testVersionsAndRootsTheUserMayNotReadAreLeftOutAndCounted(): void
    {
        $answer = self::decode($this->tool(['tl_article:36', 'tl_article:102', 'tl_page:76'])->versions('tl_article', 36));

        self::assertSame([['command' => 'contao:language:versions', 'table' => 'tl_article', 'id' => '36']], $this->inputs);
        self::assertSame([102], array_column($answer['versions'], 'id'));
        self::assertSame([76], array_column($answer['otherRoots'], 'id'));
        self::assertStringNotContainsString('Secret', json_encode($answer, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('FR intern', json_encode($answer, JSON_THROW_ON_ERROR));
        self::assertSame('1 version(s) and 1 website root(s) are outside what this user may read.', $answer['hidden']);
    }

    public function testTheMainIdGoesWhereTheMainVersionIsLeftOut(): void
    {
        $answer = ['status' => 'ok', 'table' => 'tl_article', 'record' => ['id' => 102], 'linked' => true, 'main' => 36,
            'versions' => [['id' => 36, 'title' => 'Login'], ['id' => 555, 'title' => 'Secret']], 'otherRoots' => []];

        self::assertArrayNotHasKey('main', self::decode($this->tool(['tl_article:102', 'tl_article:555'], answer: $answer)->versions('tl_article', 102)));
        self::assertSame(36, self::decode($this->tool(['tl_article:102', 'tl_article:36'], answer: $answer)->versions('tl_article', 102))['main']);
    }

    public function testEverythingReadableComesThroughWithoutANote(): void
    {
        $answer = self::decode($this->tool(['tl_article:36', 'tl_article:102', 'tl_article:555', 'tl_page:76', 'tl_page:50'])->versions('tl_article', 36));

        self::assertCount(2, $answer['versions']);
        self::assertArrayNotHasKey('hidden', $answer);
    }

    public function testARecordTheUserMayNotReadIsRefusedBeforeTheCoreRuns(): void
    {
        try {
            $this->tool([])->versions('tl_article', 36);
            self::fail('expected a denial');
        } catch (ToolAccessDeniedException) {
            self::assertSame([], $this->inputs);
        }
    }

    public function testATableWithoutTheModuleIsRefused(): void
    {
        $this->expectException(ToolAccessDeniedException::class);
        $this->tool(['tl_article:36'], module: false)->versions('tl_article', 36);
    }

    public function testATableWithoutVersionsIsRefused(): void
    {
        $this->expectException(ToolRefusedException::class);
        $this->tool([])->versions('tl_content', 1);
    }

    public function testAnAnswerWithoutItsListsDoesNotReachTheModelUnfiltered(): void
    {
        $this->expectException(ToolExecutionException::class);
        $this->tool(['tl_article:36'], answer: ['status' => 'ok', 'versions' => [['id' => 555, 'title' => 'Secret']]])->versions('tl_article', 36);
    }

    public function testOfferedOnlyWhereTheCoreHasTheCommand(): void
    {
        $user = $this->createStub(BackendUser::class);

        self::assertTrue($this->tool([])->isAccessibleBy($user));
        self::assertFalse($this->tool([], hasCommand: false)->isAccessibleBy($user));
    }
}
