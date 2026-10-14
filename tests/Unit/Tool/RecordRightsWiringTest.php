<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\DataContainerAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\FieldPermission;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\PendingActionStore;
use Webwerkwien\ContaoAiBackendBundle\Tool\AbstractCoreCommandTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\ArticleTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\ContentTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\EventTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\FaqTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\NewsTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\PageTool;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageCreateCommand;

/**
 * Every tool asks Contao's record voters (v0.18.0) with the table, the record and the
 * values it writes, through the real tool (rule 24: the test calls the place, it does
 * not copy it); a refusal runs no command. What the voters answer is Contao's — tested
 * against the real voters on c5, not here.
 */
class RecordRightsWiringTest extends TestCase
{
    /** @var list<array{string, list<mixed>}> */
    private array $asked = [];

    /**
     * @param array<string, mixed>|null $record what every lookup finds
     */
    private function records(bool $granted, ?array $record = ['id' => 5, 'pid' => 3, 'ptable' => 'tl_article', 'title' => 'Bestand', 'type' => 'text']): DataContainerAccess
    {
        $records = $this->createMock(DataContainerAccess::class);
        $records->method('fetchRecord')->willReturnCallback(function (string $table, int $id) use ($record): ?array {
            $this->asked[] = ['fetchRecord', [$table, $id]];

            return $record;
        });
        foreach (['assertRead', 'assertUpdate', 'assertDelete', 'assertCreate'] as $method) {
            $records->method($method)->willReturnCallback(function (mixed ...$arguments) use ($method, $granted, $record): ?array {
                $this->asked[] = [$method, $arguments];
                if (!$granted) {
                    throw new ToolAccessDeniedException('Contao erlaubt Ihnen nicht …');
                }

                return 'assertCreate' === $method ? null : $record;
            });
        }
        $records->method('contentParentDenial')->willReturnCallback(
            static fn (array $record): ?string => \in_array($record['ptable'] ?? 'tl_article', ['tl_article', 'tl_news', 'tl_calendar_events'], true) ? null : 'kein Elternteil, das Contao prüft',
        );

        return $records;
    }

    private function user(bool $isAdmin): BackendUser
    {
        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => match ($key) {
            'isAdmin'  => $isAdmin,
            'username' => 'redakteur',
            default    => null,
        });

        return $user;
    }

    /**
     * @template T of AbstractCoreCommandTool
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function tool(string $class, DataContainerAccess $records, ?string $runs = null, bool $isAdmin = false, ?PendingActionStore $store = null): AbstractCoreCommandTool
    {
        $arguments = [$this->createMock(ToolAccessChecker::class), $this->createStub(TokenChecker::class)];
        foreach (\array_slice((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [], 2) as $parameter) {
            $type = (string) $parameter->getType();
            if (is_subclass_of($type, Command::class)) {
                $arguments[] = $this->command($type, $type === $runs);
            } elseif (class_exists($type) || interface_exists($type)) {
                $arguments[] = $this->createMock($type);
            } else {
                $this->fail("{$class}: cannot build a {$type} for the constructor");
            }
        }

        $tool = new $class(...$arguments);
        $tool->setFieldPermission($this->createMock(FieldPermission::class));
        $tool->setAuthorizationChecker($this->createMock(AuthorizationCheckerInterface::class));
        $tool->setPendingActionStore($store ?? $this->createMock(PendingActionStore::class));
        $tool->setDataContainerAccess($records);
        $tool->setActingUserOverride($this->user($isAdmin));

        return $tool;
    }

    /**
     * @param class-string<Command> $class
     *
     * @return Command&MockObject
     */
    private function command(string $class, bool $runs): Command
    {
        $command = $this->createMock($class);
        $command->method('getDefinition')->willReturn(new InputDefinition());
        $command->expects($runs ? $this->once() : $this->never())->method('run')->willReturnCallback(
            static function (InputInterface $input, OutputInterface $output): int {
                $output->write('{"status":"ok","id":5}');

                return 0;
            },
        );

        return $command;
    }

    /**
     * @return iterable<string, array{class-string<AbstractCoreCommandTool>, string, string}>
     */
    public static function recordTools(): iterable
    {
        yield 'page'    => [PageTool::class, 'tl_page', 'title'];
        yield 'article' => [ArticleTool::class, 'tl_article', 'title'];
        yield 'content' => [ContentTool::class, 'tl_content', 'headline'];
        yield 'news'    => [NewsTool::class, 'tl_news', 'headline'];
        yield 'event'   => [EventTool::class, 'tl_calendar_events', 'title'];
        yield 'faq'     => [FaqTool::class, 'tl_faq', 'question'];
    }

    /**
     * The values as the voters compare them with the row: normalized from the shapes the
     * model sends, nulls dropped, strings.
     *
     * @dataProvider recordTools
     *
     * @param class-string<AbstractCoreCommandTool> $class
     */
    public function testAnUpdateAsksWithTheValuesItWrites(string $class, string $table, string $field): void
    {
        $tool = $this->tool($class, $this->records(false));

        try {
            $this->assertTrue(method_exists($tool, 'update'));
            $tool->update(5, [['name' => $field, 'value' => 'Neu'], ['name' => 'start', 'value' => null]]);
            self::fail('expected a denial');
        } catch (ToolAccessDeniedException) {
        }

        self::assertSame([['assertUpdate', [$table, 5, [$field => 'Neu']]]], $this->asked);
    }

    /**
     * An empty write asks for the edit right: `[]` would read as "only moved" to the
     * page voter, which needs none.
     *
     * @dataProvider recordTools
     *
     * @param class-string<AbstractCoreCommandTool> $class
     */
    public function testAnEmptyUpdateAsksForTheEditRight(string $class, string $table): void
    {
        $tool = $this->tool($class, $this->records(false));

        try {
            $this->assertTrue(method_exists($tool, 'update'));
            $tool->update(5, []);
        } catch (ToolAccessDeniedException) {
        }

        self::assertSame([['assertUpdate', [$table, 5, null]]], $this->asked);
    }

    /**
     * @dataProvider recordTools
     *
     * @param class-string<AbstractCoreCommandTool> $class
     */
    public function testAReadAsksTheReadQuestion(string $class, string $table): void
    {
        $tool = $this->tool($class, $this->records(false));

        try {
            $this->assertTrue(method_exists($tool, 'read'));
            $tool->read(5);
            self::fail('expected a denial');
        } catch (ToolAccessDeniedException) {
        }

        self::assertSame([['assertRead', [$table, 5]]], $this->asked);
    }

    /**
     * Refused before the "really delete?" question — nobody confirms what is refused.
     *
     * @dataProvider recordTools
     *
     * @param class-string<AbstractCoreCommandTool> $class
     */
    public function testARefusedDeleteNeitherAsksNorRuns(string $class, string $table): void
    {
        $store = $this->createMock(PendingActionStore::class);
        $store->expects($this->never())->method($this->anything());
        $tool = $this->tool($class, $this->records(false), null, false, $store);

        try {
            $this->assertTrue(method_exists($tool, 'delete'));
            $tool->delete(5);
            self::fail('expected a denial');
        } catch (ToolAccessDeniedException) {
        }

        self::assertSame([['assertDelete', [$table, 5]]], $this->asked);
    }

    public function testPublishingIsAWriteOfPublished(): void
    {
        try {
            $this->tool(PageTool::class, $this->records(false))->publish(5, false);
        } catch (ToolAccessDeniedException) {
        }

        self::assertSame([['assertUpdate', ['tl_page', 5, ['published' => '']]]], $this->asked);
    }

    /**
     * What each create asks: the parent is looked up, then the new record as far as it is
     * known — `sorting` non-null where the table sorts, since null means a copy.
     *
     * @return iterable<string, array{class-string<AbstractCoreCommandTool>, list<mixed>, array{string, int}, string, array<string, mixed>}>
     */
    public static function creates(): iterable
    {
        yield 'page'    => [PageTool::class, ['Weiter', 'weiter', 'forward', 3], ['tl_page', 3], 'tl_page', ['pid' => 3, 'sorting' => 0, 'type' => 'forward', 'title' => 'Weiter', 'alias' => 'weiter']];
        yield 'article' => [ArticleTool::class, ['Kopf', 3, 'header'], ['tl_page', 3], 'tl_article', ['pid' => 3, 'sorting' => 0, 'inColumn' => 'header', 'title' => 'Kopf']];
        yield 'content' => [ContentTool::class, ['html', 3, 'tl_news'], ['tl_news', 3], 'tl_content', ['pid' => 3, 'ptable' => 'tl_news', 'sorting' => 0, 'type' => 'html']];
        yield 'news'    => [NewsTool::class, [3, 'Meldung'], ['tl_news_archive', 3], 'tl_news', ['pid' => 3, 'headline' => 'Meldung']];
        yield 'event'   => [EventTool::class, [3, 'Termin'], ['tl_calendar', 3], 'tl_calendar_events', ['pid' => 3, 'title' => 'Termin']];
        yield 'faq'     => [FaqTool::class, [3, 'Frage'], ['tl_faq_category', 3], 'tl_faq', ['pid' => 3, 'sorting' => 0, 'question' => 'Frage']];
    }

    /**
     * @dataProvider creates
     *
     * @param class-string<AbstractCoreCommandTool> $class
     * @param list<mixed>                           $arguments
     * @param array{string, int}                    $parent
     * @param array<string, mixed>                  $new
     */
    public function testACreateAsksWithTheNewRecord(string $class, array $arguments, array $parent, string $table, array $new): void
    {
        $tool = $this->tool($class, $this->records(false));

        try {
            $this->assertTrue(method_exists($tool, 'create'));
            $tool->create(...$arguments);
            self::fail('expected a denial');
        } catch (ToolAccessDeniedException) {
        }

        self::assertSame([['fetchRecord', $parent], ['assertCreate', [$table, $new]]], $this->asked);
    }

    /**
     * @dataProvider creates
     *
     * @param class-string<AbstractCoreCommandTool> $class
     * @param list<mixed>                           $arguments
     */
    public function testACreateBelowAMissingParentIsRefused(string $class, array $arguments): void
    {
        $tool = $this->tool($class, $this->records(true, null));

        $this->expectException(ToolRefusedException::class);
        $this->assertTrue(method_exists($tool, 'create'));
        $tool->create(...$arguments);
    }

    public function testAGrantedCreateRuns(): void
    {
        $tool = $this->tool(PageTool::class, $this->records(true), PageCreateCommand::class);

        self::assertStringContainsString('"status":"ok"', $tool->create('Über uns', 'ueber-uns', 'regular', 3));
    }

    /**
     * The content voters let a parent table pass that they do not know; `ptable` comes
     * from the model here, so an editor only gets the tables Contao judges.
     */
    public function testAnEditorCannotCreateContentBelowAnUnjudgedTable(): void
    {
        $tool = $this->tool(ContentTool::class, $this->records(true));

        try {
            $tool->create('text', 3, 'tl_whatever');
            self::fail('expected a denial');
        } catch (ToolAccessDeniedException $e) {
            self::assertStringContainsString('kein Elternteil', $e->getMessage());
        }
        self::assertSame([], $this->asked, 'refused before any lookup');
    }

    public function testAnAdminKeepsEveryParentTable(): void
    {
        $tool = $this->tool(ContentTool::class, $this->records(true), ContentCreateCommand::class, true);

        self::assertStringContainsString('"status":"ok"', $tool->create('text', 3, 'tl_whatever'));
    }

    /**
     * Content in news and events: allowed like in the back end (Michael, 2026-10-14),
     * the voters judge the archive or calendar.
     */
    public function testAnEditorWritesContentInANews(): void
    {
        $row = ['id' => 5, 'pid' => 3, 'ptable' => 'tl_news', 'type' => 'text'];
        $tool = $this->tool(ContentTool::class, $this->records(true, $row), ContentUpdateCommand::class);

        self::assertStringContainsString('"status":"ok"', $tool->update(5, ['headline' => 'Neu']));
    }

    public function testAnEditorCannotWriteContentBelowAnUnjudgedTable(): void
    {
        $row = ['id' => 5, 'pid' => 3, 'ptable' => 'tl_form', 'type' => 'text'];
        $tool = $this->tool(ContentTool::class, $this->records(true, $row));

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessage('kein Elternteil');

        $tool->update(5, ['headline' => 'Neu']);
    }
}
