<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\RecordPermissionChecker;
use Webwerkwien\ContaoAiBackendBundle\Security\TableAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\ConsoleRunner;
use Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Support\BuildsFieldPermission;
use Webwerkwien\ContaoAiBackendBundle\Tool\LanguageTool;

/**
 * language_link adds what depends on the user — module, record, field permission —
 * and hands the rest to core's contao:language:link (v0.13.0).
 */
class LanguageToolTest extends TestCase
{
    use BuildsFieldPermission;

    /** @var list<array<string, mixed>> */
    private array $runs = [];

    /**
     * @param list<string> $allowedFields
     */
    private function tool(bool $admin, bool $tableAccess = true, array $allowedFields = []): LanguageTool
    {
        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $k) => match ($k) { 'isAdmin' => $admin, 'username' => 'editor', default => null });
        $user->method('__isset')->willReturnCallback(static fn (string $k): bool => \in_array($k, ['isAdmin', 'username'], true));

        $console = $this->createMock(ConsoleRunner::class);
        $console->method('run')->willReturnCallback(function (array $input): array {
            $this->runs[] = $input;

            return [0, '{"status":"ok","field":"master","main":4}'];
        });

        $tables = $this->createMock(TableAccess::class);
        $tables->method('canAccess')->willReturn($tableAccess);

        $tool = new LanguageTool($this->createMock(ToolAccessChecker::class), $this->createStub(TokenChecker::class), $console, $tables, $this->createMock(RecordPermissionChecker::class));
        $tool->setActingUserOverride($user);

        // The field right goes through FieldPermission like every other write (v0.17.0).
        $this->defineDca(['tl_news_archive' => ['master' => ['inputType' => 'select', 'sql' => 'int(10) unsigned NOT NULL default 0']]]);
        $tool->setFieldPermission($this->fieldPermission($allowedFields));

        return $tool;
    }

    protected function tearDown(): void
    {
        $this->restoreDca();
    }

    public function testAnArchiveLinkRunsTheCoreCommandWithTheUserAsOperator(): void
    {
        $output = $this->tool(false, true, ['tl_news_archive::master'])->link('tl_news_archive', 7, 4);

        $this->assertStringContainsString('"field":"master"', $output);
        $this->assertSame(['command' => 'contao:language:link', '--table' => 'tl_news_archive', '--id' => '7', '--main' => '4', '--operator' => 'editor'], $this->runs[0]);
    }

    public function testATableWithoutTranslationLinksIsRefused(): void
    {
        $this->expectException(ToolRefusedException::class);

        $this->tool(true)->link('tl_content', 7, 4);
    }

    public function testWithoutTheModuleNothingRuns(): void
    {
        try {
            $this->tool(false, false, ['tl_news_archive::master'])->link('tl_news_archive', 7, 4);
            $this->fail('expected a denial');
        } catch (ToolAccessDeniedException) {
        }

        $this->assertSame([], $this->runs);
    }

    public function testWithoutTheFieldPermissionNothingRuns(): void
    {
        try {
            $this->tool(false, true, [])->link('tl_news_archive', 7, 4);
            $this->fail('expected a denial');
        } catch (ToolAccessDeniedException $e) {
            $this->assertStringContainsString('tl_news_archive.master', $e->getMessage());
        }

        $this->assertSame([], $this->runs);
    }

    public function testTheCheckPassesTheRoot(): void
    {
        $this->tool(true)->check(83);

        $this->assertSame(['command' => 'contao:language:check', '--root' => '83'], $this->runs[0]);
    }
}
