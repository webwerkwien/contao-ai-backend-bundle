<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Service\SystemPromptProvider;
use Webwerkwien\ContaoAiBackendBundle\Tool\AbstractCoreCommandTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\MetaTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\RecordListTool;

/**
 * Four lists name the tables the chat may overview, and they must agree (v0.19.0):
 * record_list's allow-list, dca_schema's, the table → module map, and the module → tables
 * map the system prompt shows. The image sizes reached one of them and the changelog
 * promised all; the review before the tag found it, no test did.
 */
class TableListsConsistencyTest extends TestCase
{
    /**
     * @return list<string> sorted
     */
    private static function tables(string $class, string $name): array
    {
        $value = (new \ReflectionClassConstant($class, $name))->getValue();
        self::assertIsArray($value);

        $tables = array_map('strval', array_values($value));
        sort($tables);

        return $tables;
    }

    /**
     * @return array<string, string> table => module
     */
    private static function tableModule(): array
    {
        $value = (new \ReflectionClassConstant(AbstractCoreCommandTool::class, 'TABLE_MODULE'))->getValue();
        self::assertIsArray($value);

        $map = [];
        foreach ($value as $table => $module) {
            $map[(string) $table] = (string) $module;
        }

        return $map;
    }

    /**
     * @return array<string, list<string>> module => tables
     */
    private static function moduleTables(): array
    {
        $value = (new \ReflectionClassConstant(SystemPromptProvider::class, 'MODULE_TABLES'))->getValue();
        self::assertIsArray($value);

        $map = [];
        foreach ($value as $module => $tables) {
            self::assertIsArray($tables);
            $map[(string) $module] = array_map('strval', array_values($tables));
        }

        return $map;
    }

    public function testTheFourTableListsAgree(): void
    {
        $recordList = self::tables(RecordListTool::class, 'ALLOWED_TABLES');

        $tableModule = array_keys(self::tableModule());
        sort($tableModule);

        $moduleTables = [];
        foreach (self::moduleTables() as $tables) {
            $moduleTables = [...$moduleTables, ...$tables];
        }
        sort($moduleTables);

        self::assertSame($recordList, self::tables(MetaTool::class, 'ALLOWED_DCA_TABLES'), 'record_list and dca_schema');
        self::assertSame($recordList, $tableModule, 'record_list and the table → module map');
        self::assertSame($recordList, $moduleTables, 'record_list and the system prompt');
    }

    public function testEachTableBelongsToTheModuleThePromptFilesItUnder(): void
    {
        $tableModule = self::tableModule();

        foreach (self::moduleTables() as $module => $tables) {
            foreach ($tables as $table) {
                self::assertSame($module, $tableModule[$table] ?? null, $table);
            }
        }
    }
}
