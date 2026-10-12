<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Service;

use Contao\BackendUser;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Service\SystemPromptProvider;

/**
 * A non-admin reaches an extension's commands (v0.16.1, Consho chat test with
 * testredakteur, 2026-10-12): the two hard "refuse without looking anything up" rules
 * named no exception, and the fixed table list made a shop's data look nonexistent.
 * Through the real prompt file, rendered for an editor with only the news module.
 */
class SystemPromptExtensionsTest extends TestCase
{
    /**
     * @param list<string> $modules
     * @param list<string> $tools
     */
    private function promptFor(array $modules, array $tools): string
    {
        $data = ['isAdmin' => false, 'modules' => $modules, 'username' => 'testredakteur', 'language' => 'de'];
        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key): mixed => $data[$key] ?? null);
        // `$user->modules ?? []` asks __isset first; unanswered, every editor has no module.
        $user->method('__isset')->willReturnCallback(static fn (string $key): bool => isset($data[$key]));

        return (new SystemPromptProvider(\dirname(__DIR__, 3) . '/src/Resources/prompts/system.md'))->forUser($user, $tools);
    }

    public function testBothHardRulesNameTheExtensionException(): void
    {
        $prompt = $this->promptFor(['news'], ['news_read', 'record_list', 'ext_list', 'ext_run']);

        [$capabilities] = explode('# Acting user', $prompt, 2);
        $this->assertStringContainsString('The one exception: if `ext_list` is in your list', $capabilities);

        $at = strpos($prompt, 'If a user asks for an action whose tool is in the "NOT available" list');
        $this->assertNotFalse($at, 'the denied-tools rule is still there under its wording');
        $this->assertStringContainsString('not one of them while `ext_list` is in your list', substr($prompt, $at));
    }

    public function testTheExceptionDoesNotReopenADeniedContentTool(): void
    {
        [$capabilities] = explode('# Acting user', $this->promptFor(['news'], ['news_read', 'ext_list', 'ext_run']), 2);

        $this->assertStringContainsString('no tool in either list (available or NOT available) is named for', $capabilities);
        $this->assertStringContainsString('refuse without a lookup', $capabilities);
    }

    public function testTheTableListIsScopedToTheTableTools(): void
    {
        $prompt = $this->promptFor(['news'], ['news_read', 'record_list', 'ext_list', 'ext_run']);

        $this->assertStringContainsString('This list is the scope of record_list and the dca_* tools only.', $prompt);
        $this->assertStringContainsString("- tl_news\n- tl_news_archive", $prompt);
        $this->assertStringNotContainsString('tl_page', $prompt, 'the list itself still names only the module tables');
    }

    public function testWithoutAnyModuleTableTheEmptyListNamesTheTableToolsOnly(): void
    {
        $prompt = $this->promptFor(['consho'], ['ext_list', 'ext_run']);

        $this->assertStringContainsString('(none — record_list and the dca_* tools not usable)', $prompt);
        $this->assertStringContainsString("- ext_list\n- ext_run", $prompt);
    }
}
