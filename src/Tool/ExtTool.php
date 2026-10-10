<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\TableAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\ConsoleRunner;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;

/**
 * Commands of other extensions in the chat (v0.13.0).
 *
 * An extension makes a console command reachable for agents by declaring what it does
 * with `#[AiContract]` (core-bundle). The CLI reaches those through `ext run`; the
 * chat had no way to. Now:
 *
 * - **ext_list** names the commands this user may run: description, whether they
 *   write, which tables, what they cannot undo, arguments and options.
 * - **ext_run** runs one through the core's `contao:ai:run`, which checks the
 *   declaration and the command line and records the invocation before it starts.
 *
 * **Who sees what (decided with the maintainer, 2026-10-10).** No allow-list in the
 * configuration: the declaration is the extension author's consent, and the user's
 * back-end module rights decide the rest. A command is offered when the user may reach
 * every table its contract names in `tables` (TableAccess, the rule of Contao's own
 * TableAccessVoter); a command that names none can be checked against nothing and is
 * for admins only. A command whose contract states something `irreversible` asks the
 * user first, as the delete tools do.
 *
 * What a foreign command does inside is its own business: the contract says what it
 * promises, not what the core can guarantee. Commands under `contao:` are left out —
 * the core's own have dedicated tools.
 */
#[AsTool(
    'ext_list',
    'Lists the commands of extensions installed on this site that the current user may run '
    .'(declared for agents with #[AiContract]): name, description, whether they write, the tables they touch, '
    .'what they cannot undo, and their arguments and options. Call this before ext_run; it is the only list of valid command names.',
    method: 'listCommands',
)]
#[AsTool(
    'ext_run',
    'Runs one command from ext_list. `arguments` and `options` are objects keyed by the names ext_list gives, '
    .'e.g. {"product": "9"} and {"root": "122"}; a flag option takes true. A command that cannot be undone asks the user first: '
    .'relay the question verbatim and call again only after a yes in a later message.',
    method: 'runCommandLine',
)]
class ExtTool extends AbstractCoreCommandTool
{
    /** @var array<string, array<string, mixed>>|null name => definition, for the request */
    private ?array $catalog = null;

    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
        private readonly ConsoleRunner $console,
        private readonly TableAccess $tables,
    ) {
        parent::__construct($accessChecker, $tokenChecker);
    }

    public function getToolName(): string
    {
        return 'ext_list';
    }

    /**
     * Every chat user gets the list; it is filtered per user inside.
     */
    public function isAccessibleBy(BackendUser $user): bool
    {
        return true;
    }

    #[AiContract(writes: false, trace: [])]
    public function listCommands(): string
    {
        $this->assertAccess('ext_list');
        $user = $this->requireBackendUser();

        $commands = [];

        foreach ($this->available($user) as $name => $definition) {
            $statement = $definition['contract']['checked_with_statement'] ?? [];

            $commands[] = [
                'name'         => $name,
                'description'  => (string) ($definition['description'] ?? ''),
                'writes'       => $statement['writes'] ?? null,
                'tables'       => $definition['contract']['checked']['tables'] ?? [],
                'irreversible' => $statement['irreversible'] ?? null,
                'arguments'    => $this->describe($definition['arguments'] ?? []),
                'options'      => $this->describe($definition['options'] ?? []),
            ];
        }

        return $this->answer(0, (string) json_encode(['status' => 'ok', 'commands' => $commands], self::JSON_FLAGS), 'ext_list');
    }

    /**
     * @param array<string, scalar>|string                 $arguments by name, as ext_list gives them
     * @param array<string, scalar|list<scalar>>|string    $options   by name; true for a flag
     */
    #[AiContract(writes: true, trace: ['tl_log'], traceWhen: 'before')]
    public function runCommandLine(string $command, array|string $arguments = [], array|string $options = []): string
    {
        $this->assertAccess('ext_run');
        $user = $this->requireBackendUser();

        $arguments = $this->asArray($arguments);
        $options   = $this->asArray($options);
        $available = $this->available($user);

        if (!isset($available[$command])) {
            throw new ToolRefusedException(\sprintf(
                '"%s" is no command this user may run here. Available: %s. Call ext_list for the details.',
                $command,
                [] === $available ? 'none' : implode(', ', array_keys($available)),
            ));
        }

        $definition = $available[$command];
        $line       = $this->commandLine($command, $definition, $arguments, $options);
        $statement  = $definition['contract']['checked_with_statement'] ?? [];

        if (\is_string($statement['irreversible'] ?? null) && '' !== $statement['irreversible']) {
            $staged = $this->requireConfirmation(
                'ext_run',
                sha1($line),
                \sprintf('„%s" lässt sich nicht rückgängig machen: %s. Soll der Befehl ausgeführt werden?', $command, $statement['irreversible']),
                ['command_line' => $line],
            );

            if (null !== $staged) {
                return $staged;
            }
        }

        try {
            [$code, $output] = $this->console->run([
                'command'        => 'contao:ai:run',
                '--command-line' => $line,
                '--operator'     => (string) ($user->username ?? ''),
            ]);
        } catch (\Throwable $e) {
            if (\InvalidArgumentException::class === $e::class) {
                throw new ToolRefusedException(\sprintf('Tool "ext_run" abgelehnt: %s', $e->getMessage()), 0, $e);
            }

            throw new ToolExecutionException(\sprintf('Tool "ext_run" fehlgeschlagen: %s', $e->getMessage()), 0, $e);
        }

        return $this->answer($code, self::jsonOf($code, $output), 'ext_run');
    }

    /**
     * The command's JSON answer, or its text as one.
     *
     * A foreign command need not answer in JSON, and where it does, a log line can stand
     * before it in the same output (a refusal that Symfony also logs as console.CRITICAL,
     * measured on c5 with consho:connect:products). The last line that is a JSON object
     * is the answer; without one, the text is, and on failure also the message.
     */
    private static function jsonOf(int $code, string $output): string
    {
        $text = trim($output);

        if (\is_array(json_decode($text, true))) {
            return $text;
        }

        foreach (array_reverse(preg_split('/\R/', $text) ?: []) as $line) {
            $line = trim($line);

            if (str_starts_with($line, '{') && \is_array(json_decode($line, true))) {
                return $line;
            }
        }

        return (string) json_encode([
            'status'  => 0 === $code ? 'ok' : 'error',
            'message' => 0 === $code ? null : (mb_substr($text, 0, 500) ?: 'exit code ' . $code),
            'output'  => $text,
        ], self::JSON_FLAGS);
    }

    /**
     * The extension commands with a declaration, as the core describes them.
     *
     * @return array<string, array<string, mixed>>
     */
    private function catalog(): array
    {
        if (null !== $this->catalog) {
            return $this->catalog;
        }

        $this->catalog = [];

        [, $output] = $this->console->run(['command' => 'contao:ai:commands']);

        foreach ((array) (json_decode($output, true)['commands'] ?? []) as $entry) {
            $name = (string) ($entry['name'] ?? '');

            // Reachable outside contao: means declared (AiRunGuard); the core's own
            // contao: commands have dedicated tools.
            if ('' === $name || str_starts_with($name, 'contao:') || !($entry['reachable'] ?? false)) {
                continue;
            }

            [, $detail] = $this->console->run(['command' => 'contao:ai:commands', '--name' => $name]);
            $definition = json_decode($detail, true);

            if (\is_array($definition) && \is_array($definition['contract'] ?? null)) {
                $this->catalog[$name] = $definition;
            }
        }

        return $this->catalog;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function available(BackendUser $user): array
    {
        return array_filter($this->catalog(), function (array $definition) use ($user): bool {
            if ($user->isAdmin) {
                return true;
            }

            $tables = array_values(array_filter((array) ($definition['contract']['checked']['tables'] ?? []), 'is_string'));

            return [] !== $tables && [] === $this->tables->missing($user, $tables);
        });
    }

    /**
     * The command line for contao:ai:run: arguments in the order the command defines
     * them, options as --name=value, every value quoted.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $options
     */
    private function commandLine(string $command, array $definition, array $arguments, array $options): string
    {
        $argumentSpecs = (array) ($definition['arguments'] ?? []);
        $optionSpecs   = (array) ($definition['options'] ?? []);

        $unknown = array_diff(array_keys($arguments), array_keys($argumentSpecs));

        if ([] !== $unknown) {
            throw new ToolRefusedException(\sprintf('%s takes no argument %s. Arguments: %s.', $command, implode(', ', $unknown), implode(', ', array_keys($argumentSpecs)) ?: 'none'));
        }

        $parts = [$command];

        foreach ($argumentSpecs as $name => $spec) {
            if (!\array_key_exists($name, $arguments)) {
                if ($spec['required'] ?? false) {
                    throw new ToolRefusedException(\sprintf('%s needs the argument %s.', $command, $name));
                }

                // Positional: a later argument cannot be given without this one.
                break;
            }

            foreach ((array) $arguments[$name] as $value) {
                $parts[] = self::quote($value);
            }
        }

        foreach ($options as $name => $value) {
            $name = ltrim((string) $name, '-');

            if (!isset($optionSpecs[$name])) {
                throw new ToolRefusedException(\sprintf('%s has no option %s. Options: %s.', $command, $name, implode(', ', array_keys($optionSpecs)) ?: 'none'));
            }

            if (!($optionSpecs[$name]['accepts_value'] ?? false)) {
                if (true === $value || '1' === (string) $value || 'true' === (string) $value) {
                    $parts[] = '--' . $name;
                }

                continue;
            }

            foreach ((array) $value as $one) {
                $parts[] = '--' . $name . '=' . self::quote($one);
            }
        }

        return implode(' ', $parts);
    }

    private static function quote(mixed $value): string
    {
        return '"' . addcslashes(\is_bool($value) ? ($value ? '1' : '0') : (string) $value, '"\\') . '"';
    }

    /**
     * @param array<string, array<string, mixed>> $specs
     *
     * @return list<array{name: string, required?: bool, takes_value?: bool, description: string}>
     */
    private function describe(array $specs): array
    {
        $described = [];

        foreach ($specs as $name => $spec) {
            $entry = ['name' => (string) $name, 'description' => (string) ($spec['description'] ?? '')];

            if (\array_key_exists('required', $spec)) {
                $entry['required'] = (bool) $spec['required'];
            }

            if (\array_key_exists('accepts_value', $spec)) {
                $entry['takes_value'] = (bool) $spec['accepts_value'];
            }

            $described[] = $entry;
        }

        return $described;
    }

    /**
     * symfony/ai does not denormalize array parameters; a model may send a JSON string.
     *
     * @param array<string, mixed>|string $value
     *
     * @return array<string, mixed>
     */
    private function asArray(array|string $value): array
    {
        if (\is_string($value)) {
            $decoded = '' === trim($value) ? [] : json_decode($value, true);

            if (!\is_array($decoded)) {
                throw new ToolRefusedException('arguments and options must be objects, e.g. {"root": "122"}.');
            }

            return $decoded;
        }

        return $value;
    }
}
