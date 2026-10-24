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
 * **Who sees what.** No allow-list in the
 * configuration: the declaration is the extension author's consent, and the user's
 * back-end module rights decide the rest. A command is offered when the user may reach
 * every table its contract names in `tables` (TableAccess, the rule of Contao's own
 * TableAccessVoter); a command that names none can be checked against nothing and is
 * for admins only.
 *
 * **Nothing that acts outside the database (v0.22.1).** A command whose contract states
 * something `irreversible` — a published package release, a call to a licence server,
 * a sent mail — is not run in the chat at all, for admins neither; ext_list names it
 * under `notInChat`, ext_run refuses it with the reason. Such work belongs to the
 * command line. Until v0.22.1 the chat meant to ask first and, reading the contract in
 * the wrong place, never did.
 *
 * What a foreign command does inside is its own business: the contract says what it
 * promises, not what the core can guarantee. Commands under `contao:` are left out —
 * the core's own have dedicated tools.
 */
#[AsTool(
    'ext_list',
    'Lists the commands of extensions installed on this site that the current user may run '
    .'(declared for agents with #[AiContract]): name, description, whether they write, the tables they touch, '
    .'and their arguments and options. Commands that act outside the database are listed under notInChat with the reason: '
    .'they run only from the command line, never here. Call this before ext_run; it is the only list of valid command names.',
    method: 'listCommands',
)]
#[AsTool(
    'ext_run',
    'Runs one command from ext_list. `arguments` and `options` are objects keyed by the names ext_list gives, '
    .'e.g. {"product": "9"} and {"root": "122"}; a flag option takes true. Commands under notInChat are refused: '
    .'tell the user they run only from the command line.',
    method: 'runCommandLine',
)]
class ExtTool extends AbstractCoreCommandTool
{
    /**
     * The option through which a command learns who acts (v0.16.2; before,
     * versions were stored under the shell user). Set here from the signed-in
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
                'arguments'    => $this->describe($definition['arguments'] ?? []),
                'options'      => $this->describe(array_diff_key((array) ($definition['options'] ?? []), [self::OPERATOR => true])),
            ];
        }

        $answer = ['status' => 'ok', 'commands' => $commands];
        if ([] !== ($blocked = $this->outsideChat($user))) {
            $answer['notInChat'] = $blocked;
            $answer['notInChatNote'] = 'These act outside the database and run only from the command line (contao-ai-cli ext run), not here.';
        }

        return $this->answer(0, (string) json_encode($answer, self::JSON_FLAGS), 'ext_list');
    }

    /**
     * @param array<string,scalar>|string                   $arguments by name, as ext_list gives them
     * @param array<string,scalar|list<scalar>>|string      $options   by name; true for a flag
     */
    #[AiContract(writes: true, trace: ['tl_log'], traceWhen: 'before')]
    public function runCommandLine(string $command, array|string $arguments = [], array|string $options = []): string
    {
        $this->assertAccess('ext_run');
        $user = $this->requireBackendUser();

        $arguments = $this->asArray($arguments);
        $options   = $this->asArray($options);
        $available = $this->available($user);

        // Not in the chat at all, for nobody (v0.22.1: what does not
        // run through the DCA does not run). Said, not
        // hidden, to someone who could otherwise reach the command.
        foreach ($this->outsideChat($user) as $blocked) {
            if ($blocked['name'] === $command) {
                throw new ToolRefusedException(\sprintf(
                    '„%s" lässt sich laut seiner Erweiterung nicht rückgängig machen (%s) und läuft deshalb nicht im Chat, nur über die Befehlszeile (contao-ai-cli ext run).',
                    $command,
                    $blocked['reason'],
                ));
            }
        }

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
     * measured with consho:connect:products). The last line is the answer if it
     * is a JSON object — only the last: a text answer that echoes data somewhere in
     * the middle must not have a line of it taken for the answer. Without
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
        return array_filter($this->catalog(), fn (array $definition): bool => null === self::outsideDatabase($definition) && $this->reachableBy($user, $definition));
    }

    /**
     * The commands this user could reach but the chat does not run: their contract says
     * they do something the database cannot undo (v0.22.1). Named in ext_list, so the
     * model can say why instead of claiming the command does not exist.
     *
     * @return list<array{name: string, reason: string}>
     */
    private function outsideChat(BackendUser $user): array
    {
        $blocked = [];
        foreach ($this->catalog() as $name => $definition) {
            if (null !== ($reason = self::outsideDatabase($definition)) && $this->reachableBy($user, $definition)) {
                $blocked[] = ['name' => $name, 'reason' => $reason];
            }
        }

        return $blocked;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function reachableBy(BackendUser $user, array $definition): bool
    {
        if ($user->isAdmin) {
            return true;
        }

        $tables = array_values(array_filter((array) ($definition['contract']['checked']['tables'] ?? []), 'is_string'));

        return [] !== $tables && [] === $this->tables->missing($user, $tables);
    }

    /**
     * What a command declares the database cannot undo — a sent mail, a published
     * release, a call to another server. The core presents the contract's `irreversible`
     * as `declared.irreversible_outside_database` (since 2026-09-01); until v0.22.1 this
     * tool read `checked_with_statement.irreversible`, found nothing, and never asked —
     * the test had built the core's answer by hand, in the wrong shape.
     *
     * @param array<string, mixed> $definition
     */
    private static function outsideDatabase(array $definition): ?string
    {
        $contract = (array) ($definition['contract'] ?? []);
        $value    = $contract['declared']['irreversible_outside_database'] ?? $contract['checked_with_statement']['irreversible'] ?? null;

        if (\is_string($value) && '' !== trim($value)) {
            return $value;
        }

        // Fail closed: an `irreversible` the core could not read (true instead of a
        // sentence) is dropped from the fields and only named under `problems` — it
        // still says the command cannot be undone.
        foreach ((array) ($contract['problems'] ?? []) as $problem) {
            if (\is_string($problem) && str_starts_with($problem, 'irreversible')) {
                return 'irreversible declared, but unreadable: ' . $problem;
            }
        }

        return null;
    }

    /**
     * The command line for contao:ai:run: options first, then `--`, then the
     * arguments in the order the command defines them; every value quoted.
     *
     * Quotes alone protect nothing from Symfony's option parsing: StringInput strips
     * them while tokenizing and then parses like ArgvInput (measured against
     * symfony/console):
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
                // a word before and goes back to the model.
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
            try {
                $parts[] = self::option($command, self::OPERATOR, $operator);
            } catch (ToolCallMistakeException $e) {
                // The user name, not the model's input: nothing the model could change.
                throw new ToolRefusedException(\sprintf('Tool "ext_run" abgelehnt: der Benutzername lässt sich nicht unverändert an %s übergeben.', $command), 0, $e);
            }
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
                // dropped without a word — a write could
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
