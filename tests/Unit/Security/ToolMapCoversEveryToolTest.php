<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Security;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\SystemPromptProvider;
use Webwerkwien\ContaoAiBackendBundle\Tool\AbstractCoreCommandTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\FaqTool;
use Webwerkwien\ContaoAiCoreBundle\Command\FaqCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FaqDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FaqReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FaqUpdateCommand;

/**
 * A tool name without a TOOL_MAP entry is admin-only by accident: canUseTool() answers
 * false for every other user, so isAccessibleBy() hides the tool and assertAccess()
 * refuses it. The event and FAQ tools sat there from v0.6.0 to v0.19.0 — editors with
 * the calendar or FAQ module never saw them, and no test asked (v0.19.1).
 */
class ToolMapCoversEveryToolTest extends TestCase
{
    /**
     * Every concrete tool class under src/Tool, at any depth.
     *
     * @return list<class-string<AbstractCoreCommandTool>>
     */
    private static function toolClasses(): array
    {
        $root    = \dirname(__DIR__, 3) . '/src/Tool';
        $classes = [];
        $files   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $relative = substr($file->getPathname(), \strlen($root) + 1, -4);
            $class    = 'Webwerkwien\\ContaoAiBackendBundle\\Tool\\' . str_replace(['/', '\\'], '\\', $relative);
            self::assertTrue(class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class), $class . ' loads');
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->isSubclassOf(AbstractCoreCommandTool::class)) {
                continue;
            }
            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * The names as the shipped code reads them — getToolNames(), class- and method-level
     * attributes alike (rule 24), not a copy of its reflection.
     *
     * @return list<string>
     */
    private static function toolNames(): array
    {
        $names = [];
        foreach (self::toolClasses() as $class) {
            $tool  = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $names = [...$names, ...$tool->getToolNames()];
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @return array<string, array{module: string, op: string}>
     */
    private static function toolMap(): array
    {
        $map = (new \ReflectionClassConstant(ToolAccessChecker::class, 'TOOL_MAP'))->getValue();
        self::assertIsArray($map);

        /** @var array<string, array{module: string, op: string}> $map */
        return $map;
    }

    /**
     * @param list<string> $modules
     */
    private function editor(array $modules): BackendUser
    {
        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => match ($key) {
            'isAdmin' => false,
            'modules' => $modules,
            default   => null,
        });
        $user->method('__isset')->willReturn(true);

        return $user;
    }

    public function testEveryToolNameHasAnEntry(): void
    {
        $names = self::toolNames();
        // 14 concrete tool classes in v0.19.1; fewer means the scan lost some.
        self::assertGreaterThanOrEqual(14, \count(self::toolClasses()), 'the tool classes were found');

        self::assertSame([], array_values(array_diff($names, array_keys(self::toolMap()))));
    }

    public function testNoEntryNamesAToolThatDoesNotExist(): void
    {
        self::assertSame([], array_values(array_diff(array_keys(self::toolMap()), self::toolNames())));
    }

    /**
     * The system prompt names what a user may NOT call from a list of its own; a tool
     * missing there is neither offered nor ruled out, and the model guesses (review).
     */
    public function testTheSystemPromptKnowsEveryTool(): void
    {
        $known = (new \ReflectionClassConstant(SystemPromptProvider::class, 'ALL_KNOWN_TOOL_NAMES'))->getValue();
        self::assertIsArray($known);
        $known = array_map('strval', $known);
        sort($known);

        self::assertSame(self::toolNames(), $known);
    }

    /**
     * What the chat page counts and the agent gets, through a real tool (rule 24).
     */
    public function testAnEditorIsOfferedTheFaqToolsWithTheModuleOnly(): void
    {
        $tool = new FaqTool(
            new ToolAccessChecker(),
            $this->createStub(TokenChecker::class),
            $this->createStub(ContaoFramework::class),
            $this->createStub(FaqCreateCommand::class),
            $this->createStub(FaqUpdateCommand::class),
            $this->createStub(FaqDeleteCommand::class),
            $this->createStub(FaqReadCommand::class),
        );
        $checker = new ToolAccessChecker([$tool]);

        $offered = $checker->listAllowedTools($this->editor(['faq']));
        sort($offered);

        self::assertSame(['faq_create', 'faq_delete', 'faq_read', 'faq_update'], $offered);
        self::assertSame([], $checker->listAllowedTools($this->editor(['news'])));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function eventAndFaqTools(): iterable
    {
        foreach (['create', 'update', 'delete', 'read'] as $op) {
            yield 'event_' . $op => ['event_' . $op, 'calendar'];
            yield 'faq_' . $op   => ['faq_' . $op, 'faq'];
        }
    }

    /**
     * @dataProvider eventAndFaqTools
     */
    public function testAnEditorWithTheModuleMayUseTheTool(string $tool, string $module): void
    {
        $checker = new ToolAccessChecker();

        self::assertTrue($checker->canUseTool($this->editor([$module]), $tool), 'with the module');
        self::assertFalse($checker->canUseTool($this->editor(['news']), $tool), 'without it');
    }
}
