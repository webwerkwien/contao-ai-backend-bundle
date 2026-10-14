<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Security\RecordPermissionChecker;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\PendingActionStore;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformDescriptor;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformResolver;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\ResolvedPlatform;
use Webwerkwien\ContaoAiBackendBundle\Service\Rewriter\EntityRewriterInterface;
use Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Support\BuildsFieldPermission;
use Webwerkwien\ContaoAiBackendBundle\Tool\AbstractCoreCommandTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\ArticleTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\ContentTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\EventTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\FaqTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\NewsTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\PageTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\RecordCloneTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\RecordRewriteTool;
use Webwerkwien\ContaoAiCoreBundle\Command\NewsUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordCloneCommand;

/**
 * The field rights reach every tool: each write path is called through the real tool
 * with the real FieldPermission, and a refused write never runs its command (rule 24:
 * the test calls the place, it does not copy it).
 *
 * The record-level checks (page mounts, archives) are stubbed out; they belong to
 * RecordPermissionChecker and are tested there. Everything else is the real tool.
 */
class FieldRightsWiringTest extends TestCase
{
    use BuildsFieldPermission;

    private const TEXT = ['inputType' => 'text', 'sql' => "varchar(255) NOT NULL default ''"];
    private const FLAG = ['inputType' => 'checkbox', 'sql' => ['type' => 'boolean', 'default' => false]];
    private const DATE = ['inputType' => 'text', 'sql' => 'int(10) unsigned NULL'];

    protected function setUp(): void
    {
        $this->defineDca([
            'tl_page' => [
                'title' => self::TEXT, 'alias' => self::TEXT, 'pageTitle' => self::TEXT, 'published' => self::FLAG,
                'hide' => self::FLAG, 'description' => ['inputType' => 'textarea', 'sql' => 'text NULL'],
                'type' => ['inputType' => 'select', 'sql' => "varchar(64) NOT NULL default 'regular'"],
            ],
            'tl_article' => ['title' => self::TEXT, 'published' => self::FLAG, 'inColumn' => ['inputType' => 'select', 'sql' => "varchar(32) NOT NULL default 'main'"]],
            'tl_content' => ['headline' => self::TEXT, 'type' => ['inputType' => 'select', 'sql' => ['type' => 'string', 'length' => 64, 'default' => 'text']]],
            'tl_news' => ['headline' => self::TEXT, 'teaser' => self::TEXT, 'published' => self::FLAG, 'date' => self::DATE, 'time' => self::DATE],
            'tl_calendar_events' => ['title' => self::TEXT, 'published' => self::FLAG, 'startDate' => self::DATE],
            'tl_faq' => ['question' => self::TEXT, 'answer' => ['inputType' => 'textarea', 'sql' => 'text NULL'], 'published' => self::FLAG],
            'tl_news_archive' => ['title' => self::TEXT],
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreDca();
    }

    /**
     * @param list<string> $granted
     */
    private function wire(AbstractCoreCommandTool $tool, array $granted, ?PendingActionStore $store = null): void
    {
        $tool->setFieldPermission($this->fieldPermission($granted));
        $tool->setAuthorizationChecker($this->grantingAuthorization($granted));
        $tool->setPendingActionStore($store ?? $this->createMock(PendingActionStore::class));
        $tool->setActingUserOverride($this->editor());
    }

    private function editor(): BackendUser
    {
        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => match ($key) {
            'isAdmin'  => false,
            'username' => 'redakteur',
            default    => null,
        });

        return $user;
    }

    /**
     * @template T of Command
     *
     * @param class-string<T> $class
     *
     * @return T&MockObject
     */
    private function command(string $class, bool $runs, string $answer = '{"status":"ok","id":5}'): Command
    {
        $command = $this->createMock($class);
        $command->method('getDefinition')->willReturn(new InputDefinition());
        $command->expects($runs ? $this->once() : $this->never())->method('run')->willReturnCallback(
            static function (InputInterface $input, OutputInterface $output) use ($answer): int {
                $output->write($answer);

                return 0;
            },
        );

        return $command;
    }

    /**
     * The real tool, built from mocks for its constructor; only the record-level checks
     * are stubbed. Every command it holds must not run unless $runs names it.
     *
     * @template T of AbstractCoreCommandTool
     *
     * @param class-string<T> $class
     * @param list<string>    $stubbed the record-level check methods to switch off
     *
     * @return T
     */
    private function tool(string $class, array $stubbed, ?string $runs = null): AbstractCoreCommandTool
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

        return $this->getMockBuilder($class)->setConstructorArgs($arguments)->onlyMethods($stubbed)->getMock();
    }

    /**
     * @return iterable<string, array{class-string<AbstractCoreCommandTool>, list<string>, string, string}>
     */
    public static function updateTools(): iterable
    {
        yield 'page'    => [PageTool::class, ['assertRecordAccess', 'assertPageOperation'], 'tl_page', 'published'];
        yield 'article' => [ArticleTool::class, ['assertRecordAccess', 'assertPageAccess'], 'tl_article', 'published'];
        yield 'content' => [ContentTool::class, ['assertRecordAccess', 'assertParentAccess'], 'tl_content', 'headline'];
        yield 'news'    => [NewsTool::class, ['assertRecordAccess', 'assertArchiveAccess'], 'tl_news', 'published'];
        yield 'event'   => [EventTool::class, ['assertRecordAccess', 'assertCalendarAccess'], 'tl_calendar_events', 'published'];
        yield 'faq'     => [FaqTool::class, ['assertRecordAccess', 'assertCategoryAccess'], 'tl_faq', 'published'];
    }

    /**
     * @dataProvider updateTools
     *
     * @param class-string<AbstractCoreCommandTool> $class
     * @param list<string>                          $stubbed
     */
    public function testAnUpdateWithoutTheFieldRightRunsNothing(string $class, array $stubbed, string $table, string $field): void
    {
        $tool = $this->tool($class, $stubbed);
        $this->wire($tool, []);

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessage("{$table}.{$field}");

        $this->assertTrue(method_exists($tool, 'update'));
        $tool->update(5, [$field => '1']);
    }

    /**
     * The payload shapes the model sends are normalised before the check, so a list of
     * pairs is checked like an object (review 2026-10-14: no test sent that shape).
     *
     * @dataProvider updateTools
     *
     * @param class-string<AbstractCoreCommandTool> $class
     * @param list<string>                          $stubbed
     */
    public function testAListOfPairsIsCheckedLikeAnObject(string $class, array $stubbed, string $table, string $field): void
    {
        $tool = $this->tool($class, $stubbed);
        $this->wire($tool, []);

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessage("{$table}.{$field}");

        $this->assertTrue(method_exists($tool, 'update'));
        $tool->update(5, [['name' => $field, 'value' => '1']]);
    }

    /**
     * What each create writes beyond its link to the parent, with the right granted to
     * all but one value — the one named must be refused.
     *
     * @return iterable<string, array{class-string<AbstractCoreCommandTool>, list<string>, list<mixed>, list<string>, string}>
     */
    public static function createTools(): iterable
    {
        yield 'page: another type'      => [PageTool::class, ['assertPageOperation'], ['Weiterleitung', '', 'forward', 3], ['tl_page::title'], 'tl_page.type'];
        yield 'article: another column' => [ArticleTool::class, ['assertPageAccess'], ['Kopf', 3, 'header'], ['tl_article::title'], 'tl_article.inColumn'];
        yield 'content: another type'   => [ContentTool::class, ['assertParentAccess'], ['html', 3, 'tl_article'], [], 'tl_content.type'];
        yield 'news: the time'          => [NewsTool::class, ['assertArchiveAccess'], [1, 'Meldung', '2026-10-14'], ['tl_news::headline', 'tl_news::date'], 'tl_news.time'];
        yield 'event: the start'        => [EventTool::class, ['assertCalendarAccess'], [1, 'Termin', '2026-10-14'], ['tl_calendar_events::title'], 'tl_calendar_events.startDate'];
        yield 'faq: the answer'         => [FaqTool::class, ['assertCategoryAccess'], [1, 'Frage', 'Antwort'], ['tl_faq::question'], 'tl_faq.answer'];
    }

    /**
     * @dataProvider createTools
     *
     * @param class-string<AbstractCoreCommandTool> $class
     * @param list<string>                          $stubbed
     * @param list<mixed>                           $arguments
     * @param list<string>                          $granted
     */
    public function testACreateNeedsTheRightForWhatItSets(string $class, array $stubbed, array $arguments, array $granted, string $refused): void
    {
        $tool = $this->tool($class, $stubbed);
        $this->wire($tool, $granted);

        $this->assertTrue(method_exists($tool, 'create'));

        try {
            $tool->create(...$arguments);
            $this->fail('expected a refusal');
        } catch (ToolAccessDeniedException $e) {
            // exactly this one field is named, not the ones granted or left at the default
            $this->assertMatchesRegularExpression('/das Feld: ' . preg_quote($refused, '/') . '\. Diese/', $e->getMessage());
        }
    }

    /**
     * Nothing granted: every value a create sets other than its default is named — so a
     * create that stopped checking one of them (the title, the alias, the headline) is
     * caught, not only the one field the case above leaves out (second review).
     *
     * @return iterable<string, array{class-string<AbstractCoreCommandTool>, list<string>, list<mixed>, string}>
     */
    public static function createToolsWithoutAnyRight(): iterable
    {
        yield 'page'    => [PageTool::class, ['assertPageOperation'], ['Weiterleitung', 'weiter', 'forward', 3], 'tl_page.title, tl_page.alias, tl_page.type'];
        yield 'article' => [ArticleTool::class, ['assertPageAccess'], ['Kopf', 3, 'header'], 'tl_article.title, tl_article.inColumn'];
        yield 'news'    => [NewsTool::class, ['assertArchiveAccess'], [1, 'Meldung', '2026-10-14'], 'tl_news.headline, tl_news.date, tl_news.time'];
        yield 'event'   => [EventTool::class, ['assertCalendarAccess'], [1, 'Termin', '2026-10-14'], 'tl_calendar_events.title, tl_calendar_events.startDate'];
        yield 'faq'     => [FaqTool::class, ['assertCategoryAccess'], [1, 'Frage', 'Antwort'], 'tl_faq.question, tl_faq.answer'];
    }

    /**
     * @dataProvider createToolsWithoutAnyRight
     *
     * @param class-string<AbstractCoreCommandTool> $class
     * @param list<string>                          $stubbed
     * @param list<mixed>                           $arguments
     */
    public function testACreateWithoutAnyRightNamesEveryValueItSets(string $class, array $stubbed, array $arguments, string $refused): void
    {
        $tool = $this->tool($class, $stubbed);
        $this->wire($tool, []);
        $this->assertTrue(method_exists($tool, 'create'));

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessage('die Felder: ' . $refused . '.');

        $tool->create(...$arguments);
    }

    public function testAPageCreateWithTheDefaultTypeNeedsOnlyTheTitleRight(): void
    {
        $tool = $this->tool(PageTool::class, ['assertPageOperation'], \Webwerkwien\ContaoAiCoreBundle\Command\PageCreateCommand::class);
        $this->wire($tool, ['tl_page::title']);

        $this->assertStringContainsString('"status":"ok"', $tool->create('Über uns', '', 'regular', 3));
    }

    public function testAnUpdateWithTheRightRuns(): void
    {
        $tool = $this->tool(PageTool::class, ['assertRecordAccess'], \Webwerkwien\ContaoAiCoreBundle\Command\PageUpdateCommand::class);
        $this->wire($tool, ['tl_page::title', 'tl_page::published']);

        $this->assertStringContainsString('"status":"ok"', $tool->update(5, ['title' => 'Neu', 'published' => '1']));
    }

    /**
     * The `published` toggle needs that field, and the refusal comes before the
     * "really take it offline?" question — nobody confirms what is then refused.
     */
    public function testPagePublishWithoutTheRightNeitherAsksNorRuns(): void
    {
        $store = $this->createMock(PendingActionStore::class);
        $store->expects($this->never())->method($this->anything());

        $tool = $this->tool(PageTool::class, ['assertRecordAccess']);
        $this->wire($tool, ['tl_page::title'], $store);

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessage('tl_page.published');

        $tool->publish(5, false);
    }

    public function testPagePublishWithTheRightRuns(): void
    {
        $tool = $this->tool(PageTool::class, ['assertRecordAccess'], \Webwerkwien\ContaoAiCoreBundle\Command\PagePublishCommand::class);
        $this->wire($tool, ['tl_page::published']);

        $this->assertStringContainsString('"status":"ok"', $tool->publish(5, true));
    }

    private function cloneTool(bool $runs): RecordCloneTool
    {
        return new RecordCloneTool(
            $this->createMock(ToolAccessChecker::class),
            $this->createStub(TokenChecker::class),
            $this->command(RecordCloneCommand::class, $runs, '{"status":"ok","id":9,"table":"tl_page"}'),
            $this->createMock(RecordPermissionChecker::class),
            $this->createMock(ContaoFramework::class),
            $this->createMock(Connection::class),
        );
    }

    /**
     * Found in the review of v0.16.4: {"published": "1"} published a clone for an editor
     * whose group grants no `tl_page::published`.
     */
    public function testACloneOverrideWithoutTheRightRunsNothing(): void
    {
        $tool = $this->cloneTool(false);
        $this->wire($tool, ['tl_page::title']);

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessage('tl_page.published');

        $tool->cloneRecord('tl_page', 4, ['title' => 'Kopie', 'published' => '1']);
    }

    /**
     * A clone copies its source; it is no record with defaults. {"hide": ""} would show a
     * hidden page in the navigation, so an override needs its right even when it equals
     * the field's default (review 2026-10-14).
     */
    public function testACloneOverrideNeedsItsRightEvenAsTheDefault(): void
    {
        $tool = $this->cloneTool(false);
        $this->wire($tool, []);

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessage('tl_page.hide');

        $tool->cloneRecord('tl_page', 4, ['hide' => '']);
    }

    /**
     * A null override is written by the core too ('' or, for a flag, '0'), so it needs
     * the right like any other — skipping nulls let {"hide": null} through (second
     * review, 2026-10-14). Also as a JSON string, the shape the model sometimes sends.
     */
    public function testANullCloneOverrideNeedsItsRight(): void
    {
        $tool = $this->cloneTool(false);
        $this->wire($tool, ['tl_page::title']);

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessage('tl_page.hide, tl_page.description');

        $tool->cloneRecord('tl_page', 4, '{"title": "Kopie", "hide": null, "description": null}');
    }

    /**
     * The right is asked for the table cloned, not for tl_page.
     */
    public function testACloneOverrideIsCheckedOnTheClonedTable(): void
    {
        $tool = $this->cloneTool(false);
        $this->wire($tool, ['tl_page::title']);

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessage('tl_news_archive.title');

        $tool->cloneRecord('tl_news_archive', 4, ['title' => 'Archiv EN']);
    }

    public function testACloneWithoutOverridesNeedsNoFieldRight(): void
    {
        $tool = $this->cloneTool(true);
        $this->wire($tool, []);

        $this->assertStringContainsString('"status":"ok"', $tool->cloneRecord('tl_page', 4, []));
    }

    /**
     * @return iterable<string, array{string, class-string<Command>, string, string}>
     */
    public static function rewriteTables(): iterable
    {
        yield 'news' => ['tl_news', NewsUpdateCommand::class, 'headline', 'teaser'];
        yield 'page' => ['tl_page', PageUpdateCommand::class, 'title', 'description'];
    }

    /**
     * Skipped with its reason, not refused: the fields the user may edit are written,
     * the others appear under `skipped` (Michael, 2026-10-14). On two tables, so the
     * right is asked for the table actually rewritten (second review).
     *
     * @dataProvider rewriteTables
     *
     * @param class-string<Command> $commandClass
     */
    public function testARewriteSkipsTheFieldsWithoutARight(string $table, string $commandClass, string $granted, string $denied): void
    {
        $update = $this->createMock($commandClass);
        $update->method('getDefinition')->willReturn(new InputDefinition());
        $update->expects($this->once())->method('run')->willReturnCallback(
            function (InputInterface $input, OutputInterface $output) use ($granted): int {
                $this->assertSame([$granted . '=New text'], $input->getParameterOption('--set'));
                $output->write('{"status":"ok","id":5}');

                return 0;
            },
        );

        $rewriter = new class($table, $granted, $denied) implements EntityRewriterInterface {
            public function __construct(private readonly string $table, private readonly string $granted, private readonly string $denied)
            {
            }

            public function supports(string $table): bool
            {
                return $this->table === $table;
            }

            public function rewrite(int $id, string $instructions, PlatformInterface $platform, string $model): array
            {
                return ['id' => $id, 'table' => $this->table, 'fields' => [$this->granted => 'New text', $this->denied => 'Other text'], 'skipped' => []];
            }
        };

        $resolver = $this->createMock(PlatformResolver::class);
        $resolver->method('resolve')->willReturn(new ResolvedPlatform(
            $this->createMock(PlatformInterface::class),
            'model',
            new PlatformDescriptor('test', 'Test', null, 'test/test'),
        ));

        $tool = new RecordRewriteTool(
            $this->createMock(ToolAccessChecker::class),
            $this->createStub(TokenChecker::class),
            $this->createMock(ContaoFramework::class),
            $this->createMock(Connection::class),
            [$rewriter],
            $resolver,
            $this->createMock(RecordPermissionChecker::class),
            newsUpdate: $update instanceof NewsUpdateCommand ? $update : null,
            pageUpdate: $update instanceof PageUpdateCommand ? $update : null,
        );
        $this->wire($tool, [$table . '::' . $granted]);

        $answer = $tool->rewriteRecord($table, 5, 'auf Englisch');

        $this->assertStringContainsString('"updated":["' . $granted . '"]', $answer);
        $this->assertStringContainsString('"' . $denied . '":"Kein Recht auf das Feld', $answer);
    }
}
