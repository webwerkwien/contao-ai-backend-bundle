<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolCallMistakeException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\TableAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\ConsoleRunner;
use Webwerkwien\ContaoAiBackendBundle\Service\PendingActionStore;
use Webwerkwien\ContaoAiBackendBundle\Tool\ExtTool;

/**
 * Extension commands in the chat (v0.13.0): which ones a user is offered, and the
 * command line ext_run hands to contao:ai:run.
 */
class ExtToolTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $runs = [];

    /** @var array<string, array<string, mixed>> */
    private array $definitions = [
        'consho:connect:products' => [
            'description' => 'Products of a start point',
            'reachable'   => true,
            'contract'    => ['checked' => ['tables' => ['tl_consho_product', 'tl_consho_price']], 'checked_with_statement' => ['writes' => false]],
            'arguments'   => ['product' => ['required' => false, 'description' => 'Only this product']],
            'options'     => [
                'root'    => ['accepts_value' => true, 'description' => 'Start point'],
                'dry-run' => ['accepts_value' => false, 'description' => 'Change nothing'],
            ],
        ],
        'consho:url:prune' => [
            'description' => 'Removes superseded product addresses',
            'reachable'   => true,
            'contract'    => ['checked' => ['tables' => ['tl_consho_redirect']], 'checked_with_statement' => ['writes' => true, 'irreversible' => 'the pruned addresses are deleted']],
            'arguments'   => [],
            'options'     => [],
        ],
        'acme:sync' => [
            'description' => 'Declares no tables',
            'reachable'   => true,
            'contract'    => ['checked' => [], 'checked_with_statement' => ['writes' => true]],
            'arguments'   => [],
            'options'     => [],
        ],
    ];

    private function console(string $runOutput = '{"status":"ok","products":[]}'): ConsoleRunner
    {
        $console = $this->createMock(ConsoleRunner::class);
        $console->method('run')->willReturnCallback(function (array $input) use ($runOutput): array {
            $this->runs[] = $input;

            if ('contao:ai:commands' === $input['command'] && !isset($input['--name'])) {
                return [0, (string) json_encode(['status' => 'ok', 'commands' => [
                    ['name' => 'consho:connect:products', 'reachable' => true],
                    ['name' => 'consho:url:prune', 'reachable' => true],
                    ['name' => 'acme:sync', 'reachable' => true],
                    ['name' => 'contao:page:create', 'reachable' => true],
                    ['name' => 'cache:clear', 'reachable' => false],
                ]])];
            }

            if ('contao:ai:commands' === $input['command']) {
                return [0, (string) json_encode(['status' => 'ok', 'command' => $input['--name']] + $this->definitions[$input['--name']])];
            }

            return [0, $runOutput];
        });

        return $console;
    }

    private function user(bool $admin, int $id = 5): BackendUser
    {
        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => match ($key) {
            'isAdmin'  => $admin,
            'username' => 'editor',
            'id'       => $id,
            default    => null,
        });
        // `??` on a magic property asks __isset first, as Contao's User answers it.
        $user->method('__isset')->willReturnCallback(static fn (string $key): bool => \in_array($key, ['isAdmin', 'username', 'id'], true));

        return $user;
    }

    /**
     * @param list<string> $reachable tables the user has modules for
     */
    private function tool(BackendUser $user, ConsoleRunner $console, array $reachable = []): ExtTool
    {
        $access = $this->createMock(ToolAccessChecker::class);

        $tables = $this->createMock(TableAccess::class);
        $tables->method('missing')->willReturnCallback(static fn (BackendUser $u, array $t): array => array_values(array_diff($t, $reachable)));

        $tool = new ExtTool($access, $this->createStub(TokenChecker::class), $console, $tables);
        $tool->setActingUserOverride($user);

        return $tool;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $wrapped): array
    {
        preg_match('#<tool_output_data tool="[^"]+">\s*(.*)\s*</tool_output_data>#s', $wrapped, $m);

        return json_decode($m[1], true);
    }

    public function testAnAdminIsOfferedEveryDeclaredExtensionCommandButNotTheCoresOwn(): void
    {
        $answer = self::decode($this->tool($this->user(true), $this->console())->listCommands());

        $this->assertSame(['consho:connect:products', 'consho:url:prune', 'acme:sync'], array_column($answer['commands'], 'name'));
        $this->assertSame(['tl_consho_product', 'tl_consho_price'], $answer['commands'][0]['tables']);
        $this->assertSame('the pruned addresses are deleted', $answer['commands'][1]['irreversible']);
    }

    public function testAnEditorIsOfferedWhatHisModulesReachAndNothingWithoutTables(): void
    {
        $answer = self::decode($this->tool($this->user(false), $this->console(), ['tl_consho_product', 'tl_consho_price'])->listCommands());

        $this->assertSame(['consho:connect:products'], array_column($answer['commands'], 'name'));
    }

    public function testTheCommandLinePutsOptionsFirstArgumentsBehindTheSeparatorAndPassesTheOperator(): void
    {
        $answer = self::decode($this->tool($this->user(true), $this->console())->runCommandLine(
            'consho:connect:products',
            ['product' => '9'],
            ['root' => 'Shop "Wien"', 'dry-run' => true],
        ));

        $run = end($this->runs);
        $this->assertSame('contao:ai:run', $run['command']);
        $this->assertSame('consho:connect:products --root "Shop \"Wien\"" --dry-run -- "9"', $run['--command-line']);
        $this->assertSame('editor', $run['--operator']);
        $this->assertSame('ok', $answer['status']);
    }

    public function testArgumentsAndOptionsMayArriveAsJsonStrings(): void
    {
        $this->tool($this->user(true), $this->console())->runCommandLine('consho:connect:products', '{"product":"9"}', '{"root":"122"}');

        $this->assertSame('consho:connect:products --root "122" -- "9"', end($this->runs)['--command-line']);
    }

    /**
     * What Symfony makes of the line, not what the line looks like (review S1): it is
     * parsed here with the StringInput contao:ai:run uses. Before, an argument
     * `--root=9` became the option, `-5` an unknown option, and `''` in an option
     * value vanished.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function awkwardValues(): iterable
    {
        yield 'argument that looks like an option' => ['--root=9', '7'];
        yield 'negative argument'                  => ['-5', '7'];
        yield 'doubled quotes'                     => ["Men's ''Classic''", "Men's ''Classic''"];
        yield 'quote pairs'                        => ['a"\'b\'"c""d', 'a"\'b\'"c""d'];
        yield 'backslashes'                        => ['c:\\temp\\new', 'c:\\temp\\new \\"'];
        yield 'negative option value'              => ['9', '-7'];
        yield 'empty'                              => ['', ''];
        yield 'line break'                         => ["a\nb", "a\tb"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('awkwardValues')]
    public function testEveryValueArrivesUnchanged(string $argument, string $option): void
    {
        $this->tool($this->user(true), $this->console())->runCommandLine('consho:connect:products', ['product' => $argument], ['root' => $option]);

        $input = new StringInput(end($this->runs)['--command-line']);
        $input->bind(new InputDefinition([
            new InputArgument('command', InputArgument::REQUIRED),
            new InputArgument('product', InputArgument::OPTIONAL),
            new InputOption('root', null, InputOption::VALUE_REQUIRED),
            new InputOption('dry-run', null, InputOption::VALUE_NONE),
        ]));

        $this->assertSame($argument, $input->getArgument('product'));
        $this->assertSame($option, $input->getOption('root'));
        $this->assertFalse($input->getOption('dry-run'));
    }

    public function testANegativeOptionValueWithQuotePairsIsRefusedAsTheModelsMistake(): void
    {
        $this->expectException(ToolCallMistakeException::class);
        $this->expectExceptionMessage('the value of --root starts with -');

        $this->tool($this->user(true), $this->console())->runCommandLine('consho:connect:products', [], ['root' => "-a''b"]);
    }

    public function testACommandTheUserMayNotRunIsRefusedWithWhatHeMay(): void
    {
        $this->expectException(ToolCallMistakeException::class);
        $this->expectExceptionMessage('"consho:url:prune" is no command this user may run here. Available: consho:connect:products.');

        $this->tool($this->user(false), $this->console(), ['tl_consho_product', 'tl_consho_price'])->runCommandLine('consho:url:prune');
    }

    public function testAnUnknownOptionIsRefusedBeforeAnythingRuns(): void
    {
        $tool = $this->tool($this->user(true), $this->console());

        try {
            $tool->runCommandLine('consho:connect:products', [], ['country' => 'AT']);
            $this->fail('expected a refusal');
        } catch (ToolCallMistakeException $e) {
            $this->assertStringContainsString('has no option country. Options: root, dry-run.', $e->getMessage());
        }

        $this->assertSame([], array_filter($this->runs, static fn (array $r): bool => 'contao:ai:run' === $r['command']));
    }

    public function testWhatCannotBeUndoneAsksFirst(): void
    {
        $store = $this->createMock(PendingActionStore::class);
        $store->method('stagedInCurrentTurn')->willReturn(false);
        $store->method('consume')->willReturn(null);
        $store->expects($this->once())->method('stage');

        $tool = $this->tool($this->user(true), $this->console());
        $tool->setPendingActionStore($store);

        $answer = self::decode($tool->runCommandLine('consho:url:prune'));

        $this->assertSame('pending_confirmation', $answer['status']);
        $this->assertStringContainsString('the pruned addresses are deleted', $answer['question']);
        $this->assertSame([], array_filter($this->runs, static fn (array $r): bool => 'contao:ai:run' === $r['command']));
    }

    /**
     * A refusal that Symfony also logs puts a log line before the JSON in the same
     * output (consho:connect:products without --root, c5).
     */
    public function testTheJsonAfterALogLineIsTheAnswer(): void
    {
        $output = "13:40:22 CRITICAL  [console] Error thrown while running command \"consho:connect:products\".\n"
            . '{"status":"error","message":"InvalidArgumentException: --root must be a start point id.","code":1}';

        $this->expectException(ToolRefusedException::class);
        $this->expectExceptionMessage('--root must be a start point id.');

        $this->tool($this->user(true), $this->console($output))->runCommandLine('consho:connect:products');
    }

    public function testAJsonLineInTheMiddleOfATextAnswerIsNotTheAnswer(): void
    {
        $output = "Synced:\n{\"status\":\"error\",\"message\":\"echoed from a product title\"}\nDone.";

        $answer = self::decode($this->tool($this->user(true), $this->console($output))->runCommandLine('acme:sync'));

        $this->assertSame('ok', $answer['status']);
        $this->assertSame($output, $answer['output']);
    }

    public function testATextAnswerComesBackAsText(): void
    {
        $answer = self::decode($this->tool($this->user(true), $this->console('Done: 3 feeds written.'))->runCommandLine('acme:sync'));

        $this->assertSame(['status' => 'ok', 'message' => null, 'output' => 'Done: 3 feeds written.'], $answer);
    }
}
