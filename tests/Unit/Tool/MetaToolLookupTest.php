<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Tool\AbstractCoreCommandTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\MetaTool;
use Webwerkwien\ContaoAiCoreBundle\Command\DcaOptionsCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\DcaPaletteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\DcaSchemaCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ListingConfigCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\SearchQueryCommand;

/**
 * The chat can look fields and values up instead of guessing them (v0.16.0, "agents
 * should not guess", Michael 2026-10-12): dca_palette and dca_options, bounded like
 * dca_schema; a refusal that names the core's console lookup names the tool instead.
 * Through the real tool methods; the core commands are stand-ins that record their input.
 */
class MetaToolLookupTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $input = null;

    /**
     * @template T of \Symfony\Component\Console\Command\Command
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $answer
     *
     * @return T&\PHPUnit\Framework\MockObject\MockObject
     */
    private function command(string $class, InputDefinition $definition, array $answer): object
    {
        $command = $this->createMock($class);
        $command->method('getDefinition')->willReturn($definition);
        $command->method('run')->willReturnCallback(function (InputInterface $input, OutputInterface $output) use ($answer): int {
            $this->input = ['set' => (array) $input->getParameterOption('--set', []), 'raw' => (string) $input];
            $output->writeln((string) json_encode($answer));

            return 0;
        });

        return $command;
    }

    /**
     * @param array<string, mixed> $paletteAnswer
     * @param list<string>         $modules
     */
    private function tool(array $paletteAnswer = ['status' => 'ok'], bool $admin = true, array $modules = []): MetaTool
    {
        $tokens = $this->createStub(TokenChecker::class);
        $tokens->method('hasBackendUser')->willReturn(true);

        $palette = $this->command(DcaPaletteCommand::class, new InputDefinition([
            new InputArgument('table', InputArgument::REQUIRED),
            new InputOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
        ]), $paletteAnswer);
        $options = $this->command(DcaOptionsCommand::class, new InputDefinition([
            new InputArgument('table', InputArgument::REQUIRED),
            new InputArgument('field', InputArgument::REQUIRED),
            new InputOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
        ]), ['status' => 'ok', 'values' => ['text', 'headline']]);

        $tool = new MetaTool(
            $this->createMock(ToolAccessChecker::class),
            $tokens,
            $this->createMock(DcaSchemaCommand::class),
            $this->createMock(ListingConfigCommand::class),
            $this->createMock(SearchQueryCommand::class),
            $palette,
            $options,
        );

        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            'isAdmin' => $admin,
            'modules' => $modules,
            'username' => 'editor',
            default => null,
        });
        $tool->setActingUserOverride($user);

        return $tool;
    }

    public function testThePaletteIsAskedWithTheRecordAsSetOptions(): void
    {
        $answer = $this->tool(['status' => 'ok', 'fields' => ['type', 'headline', 'password'], 'mandatory' => ['headline', 'password']])
            ->dcaPalette('tl_content', ['type' => 'headline', 'bad key' => 'x', 'ptable' => "tl_article"]);

        $this->assertSame(['type=headline', 'ptable=tl_article'], $this->input['set'] ?? null, 'a key that is no field name is dropped');
        // The field names that never reach the agent are filtered from the palette too.
        $this->assertStringNotContainsString('password', $answer);
        $this->assertStringContainsString('"headline"', $answer);
    }

    public function testOptionsAreAskedForTableAndField(): void
    {
        $answer = $this->tool()->dcaOptions('tl_content', 'type', ['ptable' => 'tl_news']);

        $this->assertStringContainsString('tl_content', $this->input['raw'] ?? '');
        $this->assertStringContainsString('type', $this->input['raw'] ?? '');
        $this->assertSame(['ptable=tl_news'], $this->input['set'] ?? null);
        $this->assertStringContainsString('"headline"', $answer);
    }

    public function testATableOutsideTheAllowListIsRefused(): void
    {
        $this->expectException(ToolAccessDeniedException::class);

        $this->tool()->dcaPalette('tl_user', []);
    }

    public function testATableWithoutModuleAccessIsRefused(): void
    {
        $this->expectException(ToolAccessDeniedException::class);

        $this->tool(admin: false, modules: ['news'])->dcaOptions('tl_page', 'type');
    }

    public function testASensitiveFieldHasNoOptionsToShow(): void
    {
        $this->expectException(ToolAccessDeniedException::class);

        $this->tool()->dcaOptions('tl_content', 'password');
    }

    public function testARefusalNamesTheToolsInsteadOfTheConsoleCommands(): void
    {
        $message = AbstractCoreCommandTool::withToolNames(
            'Not a value Contao offers for tl_content.type: texxt. Available: text. Look the list up with `contao:dca:options tl_content type`, '
            . 'the fields of a type with `contao:dca:palette tl_content --set type=<value>`. Or `contao:dca:schema tl_page`, `contao:dca:palette tl_member`.',
        );

        $this->assertStringContainsString('the tool dca_options (table: tl_content, field: type)', $message);
        $this->assertStringContainsString('the tool dca_palette (table: tl_content, record: {"type": …})', $message);
        $this->assertStringContainsString('the tool dca_schema (table: tl_page)', $message);
        $this->assertStringContainsString('the tool dca_palette (table: tl_member)', $message);
        $this->assertStringNotContainsString('contao:dca:', $message);
    }
}
