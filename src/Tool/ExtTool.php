<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolCallMistakeException;
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
    /**
     * The option through which a command learns who acts (v0.16.2, Consho chat test
     * 2026-10-12: versions were stored under the shell user). Set here from the signed-in
     * user, never by the model — it would put any name into the version history.
     */
    private const OPERATOR = 'operator';

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
                'options'      => $this->describe(array_diff_key((array) ($definition['options'] ?? []), [self::OPERATOR => true])),
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

        // The model's mistake, handed back to it (SelfCorrectingToolbox): in the chat
        // test it invented `product:list`. The wording is the same for a command that
        // exists but is not this user's — it does not tell which.
        if (!isset($available[$command])) {
            throw new ToolCallMistakeException(\sprintf(
                '"%s" is no command this user may run here. Available: %s. Call ext_list for the details.',
                $command,
                [] === $available ? 'none' : implode(', ', array_keys($available)),
            ));
        }

        $definition = $available[$command];
        $line       = $this->commandLine($command, $definition, $arguments, $options, (string) ($user->username ?? ''));
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
     * measured on c5 with consho:connect:products). The last line is the answer if it
     * is a JSON object — only the last: a text answer that echoes data somewhere in
     * the middle must not have a line of it taken for the answer (review K5). Without
     * one, the text is, and on failure also the message.
     */
    private static function jsonOf(int $code, string $output): string
    {
        $text = trim($output);

        if (\is_array(json_decode($text, true))) {
            return $text;
        }

        $lines = preg_split('/\R/', $text) ?: [];
        $last  = trim((string) end($lines));

        if (str_starts_with($last, '{') && \is_array(json_decode($last, true))) {
            return $last;
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
     * The command line for contao:ai:run: options first, then `--`, then the
     * arguments in the order the command defines them; every value quoted.
     *
     * Quotes alone protect nothing from Symfony's option parsing: StringInput strips
     * them while tokenizing and then parses like ArgvInput (pre-release review S1,
     * measured against symfony/console):
     *
     * - An argument `--root=9` became the option root, `-5` an unknown option.
     *   Behind `--` every token is an argument.
     * - An option written `--name="value"` loses `''` and `"'` (StringInput deletes
     *   them in that form): `Men's ''Classic''` arrived as `Men's Classic`. Written
     *   as its own token, `--name "value"`, it arrives unchanged.
     * - Only a value that starts with `-` needs the `=` form — as its own token it
     *   would be read as the next option. One of those that also contains `''` or
     *   `"'` cannot be passed faithfully at all and is refused.
     *
     * A command with an `operator` option gets the signed-in user there; the model can
     * neither see nor set it (self::OPERATOR).
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $options
     */
    private function commandLine(string $command, array $definition, array $arguments, array $options, string $operator): string
    {
        $argumentSpecs = (array) ($definition['arguments'] ?? []);
        $allOptions    = (array) ($definition['options'] ?? []);
        $optionSpecs   = array_diff_key($allOptions, [self::OPERATOR => true]);

        $unknown = array_diff(array_keys($arguments), array_keys($argumentSpecs));

        if ([] !== $unknown) {
            throw new ToolCallMistakeException(\sprintf('%s takes no argument %s. Arguments: %s.', $command, implode(', ', $unknown), implode(', ', array_keys($argumentSpecs)) ?: 'none'));
        }

        $parts = [$command];

        foreach ($options as $name => $value) {
            $name = ltrim((string) $name, '-');

            if (self::OPERATOR === $name && isset($allOptions[$name])) {
                throw new ToolCallMistakeException(\sprintf('--%s of %s is set to the signed-in user; leave it out.', $name, $command));
            }

            if (!isset($optionSpecs[$name])) {
                throw new ToolCallMistakeException(\sprintf('%s has no option %s. Options: %s.', $command, $name, implode(', ', array_keys($optionSpecs)) ?: 'none'));
            }

            if (!($optionSpecs[$name]['accepts_value'] ?? false)) {
                // A flag is on or off; anything else ("yes", a list) was dropped without
                // a word before (second pre-release review) and goes back to the model.
                $flag = \is_scalar($value) || null === $value ? strtolower(var_export($value, true)) : 'list';

                if (\in_array($flag, ['true', "'1'", '1', "'true'"], true)) {
                    $parts[] = '--' . $name;
                } elseif (!\in_array($flag, ['false', "'0'", '0', "'false'", "''", 'null'], true)) {
                    throw new ToolCallMistakeException(\sprintf('--%s of %s is a flag: give true or false.', $name, $command));
                }

                continue;
            }

            foreach ((array) $value as $one) {
                $parts[] = self::option($command, $name, self::text($one));
            }
        }

        if ('' !== $operator && ($allOptions[self::OPERATOR]['accepts_value'] ?? false)) {
            $parts[] = self::option($command, self::OPERATOR, $operator);
        }

        $positional = [];
        $names      = array_keys($argumentSpecs);

        foreach ($names as $index => $name) {
            $spec = $argumentSpecs[$name];

            if (!\array_key_exists($name, $arguments)) {
                if ($spec['required'] ?? false) {
                    throw new ToolCallMistakeException(\sprintf('%s needs the argument %s.', $command, $name));
                }

                // Positional: a later argument cannot be given without this one. It was
                // dropped without a word (second pre-release review, M-B) — a write could
                // run with half its input.
                $later = array_values(array_intersect(\array_slice($names, $index + 1), array_keys($arguments)));

                if ([] !== $later) {
                    throw new ToolCallMistakeException(\sprintf('%s: the argument %s needs %s before it — arguments are positional.', $command, implode(', ', $later), $name));
                }

                break;
            }

            foreach ((array) $arguments[$name] as $value) {
                $positional[] = self::quote(self::text($value));
            }
        }

        if ([] !== $positional) {
            $parts[] = '--';
            array_push($parts, ...$positional);
        }

        return implode(' ', $parts);
    }

    private static function option(string $command, string $name, string $value): string
    {
        if (!str_starts_with($value, '-')) {
            return '--' . $name . ' ' . self::quote($value);
        }

        if (str_contains($value, "''") || str_contains($value, '"\'')) {
            throw new ToolCallMistakeException(\sprintf("%s: the value of --%s starts with - and contains '' or \"' — the console cannot pass that unchanged. Leave out the leading - or the quotes.", $command, $name));
        }

        return '--' . $name . '=' . self::quote($value);
    }

    private static function text(mixed $value): string
    {
        return \is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    private static function quote(string $value): string
    {
        return '"' . addcslashes($value, '"\\') . '"';
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
                throw new ToolCallMistakeException('arguments and options must be objects, e.g. {"root": "122"}.');
            }

            return $decoded;
        }

        return $value;
    }
}
