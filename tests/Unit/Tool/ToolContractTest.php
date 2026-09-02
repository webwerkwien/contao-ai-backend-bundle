<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Tool\MetaTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\NewsTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\PageTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\RecordCloneTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\RecordListTool;
use Webwerkwien\ContaoAiCoreBundle\Contract\ContractReader;

/**
 * Every tool says what it does, and the reader can hear it.
 *
 * Declaring is worth nothing if it lands in the wrong place. On 2026-09-02 the
 * first attempt put all five contracts of `PageTool` at **class** level,
 * because `#[AsTool]` sits above the class here and names its method by
 * argument. It looked applied. Every method would then have answered with the
 * same contract — the one belonging to `create`, so `page_read` would have
 * claimed to write.
 *
 * These tests therefore read through `ContractReader` instead of checking that
 * the attribute appears somewhere in the file.
 */
class ToolContractTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, string, bool}>
     */
    public static function toolMethods(): iterable
    {
        yield 'page create'  => [PageTool::class, 'create', true];
        yield 'page update'  => [PageTool::class, 'update', true];
        yield 'page delete'  => [PageTool::class, 'delete', true];
        yield 'page read'    => [PageTool::class, 'read', false];
        yield 'page publish' => [PageTool::class, 'publish', true];
        yield 'news delete'  => [NewsTool::class, 'delete', true];
    }

    /**
     * @dataProvider toolMethods
     *
     * @param class-string $class
     */
    public function testEachMethodAnswersWithItsOwnContract(string $class, string $method, bool $writes): void
    {
        if (!method_exists($class, $method)) {
            self::markTestSkipped($class.'::'.$method.'() does not exist');
        }

        $contract = ContractReader::read($class, $method);
        $where    = $class.'::'.$method;

        self::assertNotNull($contract, $where.' declares nothing');
        self::assertSame([], $contract['problems'], $where.': '.implode(' | ', $contract['problems']));
        self::assertSame($writes, $contract['fields']['writes'], $where.' writes flag');
    }

    public function testADeleteAndAReadOnOneClassDoNotShareAContract(): void
    {
        // The failure the first attempt would have produced: one contract at
        // class level, so a read would have claimed to write.
        $delete = ContractReader::read(PageTool::class, 'delete');
        $read   = ContractReader::read(PageTool::class, 'read');

        self::assertTrue($delete['fields']['writes']);
        self::assertFalse($read['fields']['writes']);
        self::assertSame(['tl_undo', 'tl_log'], $delete['fields']['trace']);
        self::assertSame([], $read['fields']['trace']);
    }

    public function testADeleteLeavesUndoAndAnUpdateLeavesAVersion(): void
    {
        // Measured in the core bundle rather than assumed: ModelWriter::delete()
        // snapshots to tl_undo, the update path calls createVersion(), and
        // logSuccess() runs from outputSuccess() — hence 'on-success' for both.
        $delete = ContractReader::read(PageTool::class, 'delete');
        $update = ContractReader::read(PageTool::class, 'update');

        self::assertContains('tl_undo', $delete['fields']['trace']);
        self::assertContains('tl_version', $update['fields']['trace']);
        self::assertSame('on-success', $update['fields']['traceWhen']);
    }

    public function testTheGenericToolsClaimNoTable(): void
    {
        // record_clone and record_list take the table as a parameter. Naming one
        // here would be a claim about something the caller decides.
        foreach ([RecordCloneTool::class => 'cloneRecord', RecordListTool::class => 'list'] as $class => $method) {
            if (!method_exists($class, $method)) {
                continue;
            }

            $contract = ContractReader::read($class, $method);

            self::assertNotNull($contract, $class.' declares nothing');
            self::assertArrayNotHasKey('tables', $contract['fields'], $class.' must not name a table');
        }
    }

    public function testTheMetaToolsDeclareThemselvesReadOnly(): void
    {
        foreach (['dcaSchema', 'listingConfig', 'searchQuery'] as $method) {
            if (!method_exists(MetaTool::class, $method)) {
                continue;
            }

            $contract = ContractReader::read(MetaTool::class, $method);

            self::assertNotNull($contract, 'MetaTool::'.$method.' declares nothing');
            self::assertFalse($contract['fields']['writes'], 'MetaTool::'.$method.' must not claim to write');
        }
    }
}
