<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Security;

use Contao\BackendUser;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;

/**
 * A tool name without a TOOL_MAP entry is admin-only by accident: canUseTool() answers
 * false for every other user, so isAccessibleBy() hides the tool and assertAccess()
 * refuses it. The event and FAQ tools sat there from v0.6.0 to v0.19.0 — editors with
 * the calendar or FAQ module never saw them, and no test asked (v0.19.1).
 */
class ToolMapCoversEveryToolTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function toolNames(): array
    {
        $names = [];
        foreach (glob(\dirname(__DIR__, 3) . '/src/Tool/*.php') ?: [] as $file) {
            $class = 'Webwerkwien\\ContaoAiBackendBundle\\Tool\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            foreach ((new \ReflectionClass($class))->getAttributes(AsTool::class) as $attribute) {
                $names[] = $attribute->newInstance()->name;
            }
        }
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
        self::assertNotEmpty($names, 'the tool classes were found');

        self::assertSame([], array_values(array_diff($names, array_keys(self::toolMap()))));
    }

    public function testNoEntryNamesAToolThatDoesNotExist(): void
    {
        self::assertSame([], array_values(array_diff(array_keys(self::toolMap()), self::toolNames())));
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
