<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolCallMistakeException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\DataContainerAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\FieldPermission;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\PendingActionStore;

abstract class AbstractCoreCommandTool
{
    /**
     * Pending-action store for two-step destructive flows. Set via setter
     * injection so the existing constructors of every concrete tool subclass
     * keep working unchanged — Symfony's autowiring fills it in automatically.
     */
    protected PendingActionStore $pendingActionStore;

    /**
     * Symfony Security authorization checker. Contao 5 dropped the
     * `BackendUser::CAN_*` numeric class constants in favour of voter-based
     * permission strings (see ContaoCorePermissions::USER_CAN_*); subclasses
     * use isGranted() instead of the old User::isAllowed() helper. Setter
     * injection so subclass constructors stay unchanged.
     */
    protected AuthorizationCheckerInterface $authorizationChecker;

    public function __construct(
        protected readonly ToolAccessChecker $accessChecker,
        protected readonly TokenChecker $tokenChecker,
    ) {
    }

    #[Required]
    public function setPendingActionStore(PendingActionStore $store): void
    {
        $this->pendingActionStore = $store;
    }

    #[Required]
    public function setAuthorizationChecker(AuthorizationCheckerInterface $checker): void
    {
        $this->authorizationChecker = $checker;
    }

    /**
     * Contao's field rights ("Erlaubte Felder") for every write (v0.17.0). Setter
     * injection like the two above; unset, a write fails instead of skipping the check.
     */
    protected FieldPermission $fieldPermission;

    #[Required]
    public function setFieldPermission(FieldPermission $fieldPermission): void
    {
        $this->fieldPermission = $fieldPermission;
    }

    /**
     * Contao's record rights (v0.18.0): the data container voters, asked like `DC_Table`
     * asks them. Setter injection like the others.
     */
    protected DataContainerAccess $dcAccess;

    #[Required]
    public function setDataContainerAccess(DataContainerAccess $dcAccess): void
    {
        $this->dcAccess = $dcAccess;
    }

    /**
     * @param list<string> $fields
     *
     * @throws ToolAccessDeniedException when the user lacks the right to one of them
     */
    protected function assertFieldsWritable(string $table, array $fields): void
    {
        $this->fieldPermission->assertWritable($this->requireBackendUser(), $table, $fields);
    }

    /**
     * The values a write sets, as the record voters compare them with the stored row:
     * normalized, without the nulls buildSetOptions() drops, as strings. Null for an
     * empty write — an UpdateAction with `[]` reads as "only moved" to the page voter,
     * which needs no edit right; null asks for the edit right like opening the form.
     *
     * @param array<int|string, mixed> $fields
     *
     * @return array<string, string>|null
     */
    protected static function writtenValues(array $fields): ?array
    {
        $values = [];
        foreach (self::normalizeFieldsPayload($fields) as $key => $value) {
            if (null !== $value) {
                // An object compares as its JSON, not as "Array" (v0.19.2); buildSetOptions()
                // decides whether it may be written at all.
                $values[(string) $key] = \is_array($value) ? (string) json_encode($value) : (string) $value;
            }
        }

        return [] === $values ? null : $values;
    }

    /**
     * @param array<string, scalar|null> $values the values a create sets
     *
     * @throws ToolAccessDeniedException when a value other than the default needs a right the user lacks
     */
    protected function assertFieldsCreatable(string $table, array $values): void
    {
        $this->fieldPermission->assertCreatable($this->requireBackendUser(), $table, $values);
    }

    /**
     * Primary tool name — used as anchor for class-level permission lookup
     * via ToolAccessChecker. The class-level permission applies to all sub-tools
     * registered via #[AsTool] in this class.
     */
    abstract public function getToolName(): string;

    /**
     * Returns ALL #[AsTool] names registered on this class (class-level + method-level
     * attributes). Read via reflection so the list stays in sync with the actual tools
     * exposed to the agent.
     *
     * @return list<string>
     */
    public function getToolNames(): array
    {
        $names = [];
        $refl = new \ReflectionClass(static::class);

        foreach ($refl->getAttributes(AsTool::class) as $attr) {
            $args = $attr->getArguments();
            $name = $args['name'] ?? $args[0] ?? null;
            if (\is_string($name) && '' !== $name) {
                $names[$name] = true;
            }
        }
        foreach ($refl->getMethods() as $method) {
            foreach ($method->getAttributes(AsTool::class) as $attr) {
                $args = $attr->getArguments();
                $name = $args['name'] ?? $args[0] ?? null;
                if (\is_string($name) && '' !== $name) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * Returns false if no allowed sub-tool exists for the user. The actual
     * per-method assertion happens inside each tool method via assertAccess().
     */
    public function isAccessibleBy(BackendUser $user): bool
    {
        return $this->accessChecker->canUseTool($user, $this->getToolName());
    }

    /**
     * Helper for subclasses — returns the current BackendUser or throws.
     *
     * @throws ToolAccessDeniedException
     */
    protected function requireBackendUser(): BackendUser
    {
        $user = $this->getCurrentBackendUser();
        if (null === $user) {
            throw new ToolAccessDeniedException('Keine aktive Backend-Session.');
        }
        return $user;
    }

    /**
     * Two-step gate for destructive tool calls (delete, unpublish). The first
     * call to a destructive method should run access + record checks, then
     * invoke this helper. If no fresh staged entry exists for this user/tool/key,
     * the entry is created and a `pending_confirmation` JSON payload is
     * returned wrapped in the same `<tool_output_data>` sentinel as a normal
     * tool output. The LLM is expected to relay the confirmation question to
     * the user verbatim and re-invoke the same tool with the same key after
     * a positive reply; the second call passes through and executes.
     *
     * Storing the staged entry server-side rather than in the chat history
     * is what makes confirmation flows survive the Smart-History
     * stub: the agent's own assistant text is replaced before persistence,
     * but the pending entry lives in a separate session slot keyed by user.
     *
     * Returns null when staging happened (caller should return the result),
     * or returns nothing when the action has been confirmed and the caller
     * should proceed to actually run the wrapped command. A return of null
     * is the "stage" branch; an explicit return value (a JSON string in the
     * sentinel wrapper) is what the caller hands back to the LLM.
     *
     * @param array<string, mixed> $stagePayload data echoed back to the LLM
     *   for the confirmation prompt — title, archive name, etc.
     */
    protected function requireConfirmation(string $tool, string $key, string $humanQuestion, array $stagePayload): ?string
    {
        $user = $this->getCurrentBackendUser();
        if (null === $user) {
            // No session: cannot stage; let the caller execute. Tests / CLI fall here.
            return null;
        }
        $userId = (int) ($user->id ?? 0);
        if (0 === $userId) {
            return null;
        }
        // 🔴 A second call in the SAME turn no longer triggers anything.
        // Before, the model could call here twice in a row — the question to the
        // user was only a sentence in the `note`, and since symfony/ai 0.13 the
        // tool loop drives the model itself.
        if ($this->pendingActionStore->stagedInCurrentTurn($userId, $tool, $key)) {
            $payload = [
                'status'   => 'awaiting_user',
                'tool'     => $tool,
                'question' => $humanQuestion,
                'note'     => 'Diese Aktion wurde in diesem Turn bereits zur Bestätigung vorgemerkt. Rufe das Werkzeug JETZT NICHT ERNEUT auf. Stelle dem Benutzer die Frage wörtlich und warte auf seine Antwort. Der Server führt frühestens nach einer neuen Nachricht des Benutzers aus.',
            ];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG);

            return "<tool_output_data tool=\"{$tool}\">\n{$json}\n</tool_output_data>";
        }

        if (null !== $this->pendingActionStore->consume($userId, $tool, $key)) {
            return null; // confirmed -> caller proceeds
        }
        $this->pendingActionStore->stage($userId, $tool, $key, $stagePayload);
        $payload = [
            'status'   => 'pending_confirmation',
            'tool'     => $tool,
            'question' => $humanQuestion,
            'stage'    => $stagePayload,
            'note'     => 'Frage den User wörtlich und warte auf seine Antwort. Bei klarem Ja in einer SPÄTEREN Nachricht denselben Tool-Aufruf erneut absetzen — dann führt der Server aus. Ein erneuter Aufruf in diesem Turn bewirkt nichts. Bei Nein nicht erneut aufrufen.',
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG);
        return "<tool_output_data tool=\"{$tool}\">\n{$json}\n</tool_output_data>";
    }

    /**
     * Truncation cap for free-text fields inside tool outputs. Anything beyond gets
     * clipped with a `…[truncated]` suffix so a single bloated DB row cannot blow the
     * context budget — and an embedded prompt-injection payload runs out of room.
     */
    protected const FIELD_TRUNCATION_BYTES = 500;

    /**
     * `JSON_HEX_TAG` was added for a reason that is not visible without
     * measuring.
     *
     * The concern was: record content could close the sentinel
     * (`</tool_output_data>`) and appear as an instruction after it. Measured, that
     * was **not** the case — `json_encode()` escapes the slash to
     * `<\/tool_output_data>`, the attack did not work.
     *
     * 🎯 But that was **accidental protection**. Nobody had chosen `json_encode` for
     * it; a `JSON_UNESCAPED_SLASHES` added later — a plausible readability change —
     * would have removed it silently, and the OPENING tag got through anyway,
     * because it contains no slash.
     *
     * `JSON_HEX_TAG` turns the accident into a promise: `<` and `>` become
     * `<`/`>`, so no tag at all can form in the data. The way back through
     * `json_decode()` yields the original value — nothing changes for the model,
     * everything for an attacker.
     */
    protected const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG;

    /**
     * The sibling a new record goes behind (`after`, v0.22.0): its stored row, whose
     * parent (and for tl_content parent table) the new record takes — so the voters
     * judge the create below that parent, as for `pid`. The sibling must be readable;
     * a core before v1.12.0 has no `--after` and is named instead of failing on the
     * unknown option.
     *
     * @return array<string, mixed>
     */
    protected function siblingForAfter(Command $create, string $table, int $after): array
    {
        if (!$create->getDefinition()->hasOption('after')) {
            throw new ToolRefusedException('"after" braucht contao-ai-core-bundle v1.12.0 auf diesem Server; hier stattdessen ans Ende anlegen und danach verschieben.');
        }

        $sibling = $this->dcAccess->fetchRecord($table, $after);
        if (null === $sibling) {
            throw new ToolRefusedException(\sprintf('Datensatz %d in "%s" nicht gefunden — hinter ihn kann nichts angelegt werden.', $after, $table));
        }
        if (!$this->dcAccess->isGranted(new \Contao\CoreBundle\Security\DataContainer\ReadAction($table, $sibling))) {
            throw new ToolAccessDeniedException(\sprintf('Kein Lesezugriff auf %s:%d.', $table, $after));
        }

        return $sibling;
    }

    /**
     * Tool outputs are returned as a JSON-encoded string wrapped in a sentinel
     * tag pair. The system prompt instructs the model to treat anything inside the
     * wrapper as untrusted data, mitigating prompt injection via DB content.
     *
     * @param array<string, mixed> $arguments
     */
    protected function runCommand(Command $command, array $arguments, string $toolName): string
    {
        $this->assertAccess($toolName);

        // Stamp the audit trail with the actual Contao backend user. The core
        // bundle accepts an optional `--operator` on every write command — when the
        // CLI runs the same command, the option stays empty and it falls back to
        // $_SERVER['USER']. We only set it when (a) the command declares the option
        // and (b) the caller did not already pass one.
        // A null value means "not passed". Without this, an optional argument
        // has to be assembled in a variable before the call:
        //
        //     $args = ['--title' => $title];
        //     if (null !== $date) { $args['--date'] = $date; }
        //     return $this->runCommand($this->createCommand, $args, 'news_create');
        //
        // 🎯 Every `create` was written that way, and that is how they escaped
        // {@see ToolArgumentsMatchCommandTest}: its scan reads the array literal
        // *inside* the call, so `news_create` had never been checked since the
        // day it was written — in the very test built after `page_publish`
        // shipped broken for exactly this reason. Found by mutation on
        // 2026-09-02 while adding EventTool: `--title` was renamed to `--titel`
        // and the suite stayed green.
        //
        // Making null mean absence lets the keys stay inline, which puts them
        // back in front of the checker. Nothing passed a deliberate null before:
        // it would have meant "option without a value", which no caller wants.
        $arguments = array_filter($arguments, static fn ($value): bool => null !== $value);

        $definition = $command->getDefinition();
        if ($definition->hasOption('operator') && !\array_key_exists('--operator', $arguments)) {
            $user = $this->getCurrentBackendUser();
            if (null !== $user) {
                $arguments['--operator'] = (string) ($user->username ?? '');
            }
        }

        $input  = new ArrayInput($arguments);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        try {
            $exitCode = $command->run($input, $output);
        } catch (\Throwable $e) {
            throw new ToolExecutionException(\sprintf('Tool "%s" konnte nicht ausgeführt werden: %s', $toolName, $e->getMessage()), 0, $e);
        }

        return $this->answer($exitCode, $output->fetch(), $toolName);
    }

    /**
     * A command's JSON answer as the tool's output: a refusal or a defect thrown as
     * such, strings truncated, the rest wrapped in the sentinel (v0.13.0: shared with
     * the tools that run commands through ConsoleRunner).
     *
     * @throws ToolExecutionException
     * @throws ToolRefusedException
     */
    protected function answer(int $exitCode, string $output, string $toolName): string
    {
        $raw = trim($output);

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $excerpt = mb_substr($raw, 0, 300);
            throw new ToolExecutionException(
                \sprintf('Tool "%s" lieferte kein gültiges JSON (exit %d): %s', $toolName, $exitCode, $excerpt),
                0,
                $e,
            );
        }

        if (!\is_array($decoded)) {
            throw new ToolExecutionException(\sprintf('Tool "%s" lieferte kein JSON-Objekt zurück.', $toolName));
        }

        if (Command::SUCCESS !== $exitCode || ($decoded['status'] ?? null) === 'error') {
            $message = self::withToolNames(self::failureMessage($decoded) . self::contaoMessages($decoded));

            // core-bundle v1.3.0 names a defect: `exception` on the answer, or on an
            // entry of a bulk update's `errors`. That is a crash, not a refusal —
            // tool_failed with a report. An older core never sends the field, and
            // everything stays a refusal as before.
            if (self::isDefect($decoded)) {
                throw new ToolExecutionException(\sprintf('Tool "%s" fehlgeschlagen: %s', $toolName, $message));
            }

            // 🔴 Until 2026-09-02 this said `ToolExecutionException`, which turned
            // a wrong ID into HTTP 500. A record that does not exist is no server
            // error — and since contao-ai-cli v0.15.0, a 500 in
            // `bridge configure --test` explicitly means "token fine, bridge
            // broken". A typo would have accused a healthy bridge.
            //
            // We are here because the command returned a well-formed error
            // object: it ran, understood the request and refused it. That is a
            // statement to the caller, not a defect. See ToolRefusedException for
            // the boundary and its price.
            //
            // The core prefixes every message with the class (JsonErrorBoundary);
            // for a refusal that is always `InvalidArgumentException:`, which
            // tells an editor nothing.
            throw new ToolRefusedException(\sprintf('Tool "%s" abgelehnt: %s', $toolName, self::withoutRefusalClass($message)));
        }

        $decoded = $this->truncateStrings($decoded);
        $this->postProcessDecoded($decoded, $toolName);

        $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG);
        if (false === $json) {
            throw new ToolExecutionException(\sprintf('Tool "%s" Ausgabe konnte nicht serialisiert werden.', $toolName));
        }

        return "<tool_output_data tool=\"{$toolName}\">\n{$json}\n</tool_output_data>";
    }

    private const MAX_REASONS = 5;

    /**
     * The core names its own console commands as the lookup in a refusal
     * (`contao:dca:options tl_content type`); in the chat they are tools (v0.16.0,
     * "agents should not guess"). A command the chat has no tool
     * for stays as it is.
     */
    public static function withToolNames(string $message): string
    {
        $message = (string) preg_replace('/`contao:dca:options ([A-Za-z0-9_]+) ([A-Za-z0-9_]+)`/', 'the tool dca_options (table: $1, field: $2)', $message);
        $message = (string) preg_replace('/`contao:dca:palette ([A-Za-z0-9_]+) --set ([A-Za-z0-9_]+)=[^`]*`/', 'the tool dca_palette (table: $1, record: {"$2": …})', $message);
        $message = (string) preg_replace('/`contao:dca:palette ([A-Za-z0-9_]+)`/', 'the tool dca_palette (table: $1)', $message);

        return (string) preg_replace('/`contao:dca:schema ([A-Za-z0-9_]+)`/', 'the tool dca_schema (table: $1)', $message);
    }

    private const MESSAGE_LABELS =['error' => 'Fehler', 'confirm' => 'Bestätigung', 'new' => 'Neu', 'info' => 'Hinweis', 'raw' => 'Hinweis'];

    /**
     * What the callbacks told the editor (core-bundle v1.6.0: `messages`), for an answer
     * that is thrown rather than handed over whole — a callback that refuses often says
     * why there and nowhere else. A success carries the field in its JSON as it is.
     *
     * @param array<mixed> $decoded
     */
    public static function contaoMessages(array $decoded): string
    {
        $parts = [];

        foreach (\is_array($decoded['messages'] ?? null) ? $decoded['messages'] : [] as $message) {
            if (!\is_array($message) || !\is_string($message['text'] ?? null) || '' === trim($message['text'])) {
                continue;
            }

            $label   = self::MESSAGE_LABELS[(string) ($message['type'] ?? '')] ?? 'Hinweis';
            $parts[] = $label . ': ' . mb_substr(trim($message['text']), 0, 500);
        }

        if ([] === $parts) {
            return '';
        }

        $more = \count($parts) - self::MAX_REASONS;

        return ' — Contao meldet: ' . implode('; ', \array_slice($parts, 0, self::MAX_REASONS)) . ($more > 0 ? \sprintf(' … und %d weitere', $more) : '');
    }

    /**
     * A refusal's message without the class the core puts in front of it.
     */
    public static function withoutRefusalClass(string $message): string
    {
        return str_starts_with($message, 'InvalidArgumentException: ') ? substr($message, \strlen('InvalidArgumentException: ')) : $message;
    }

    /**
     * What went wrong, in one line — also for answers without a `message`.
     *
     * A bulk update with `--ids` answers `status: partial` with the reasons per
     * record in `errors` and no `message`; this used to read "unbekannter Fehler"
     * and drop every reason. At most five reasons.
     *
     * @param array<mixed> $decoded
     */
    public static function failureMessage(array $decoded): string
    {
        if (isset($decoded['message']) && \is_string($decoded['message']) && '' !== $decoded['message']) {
            return $decoded['message'];
        }

        $errors = \is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [];
        if ([] === $errors) {
            return 'unbekannter Fehler';
        }

        $reasons = [];
        foreach ($errors as $error) {
            if (\is_array($error)) {
                $reasons[] = \sprintf('%s: %s', (string) ($error['id'] ?? '?'), (string) ($error['message'] ?? 'unbekannter Fehler'));
            }
        }

        // Five are enough to see the pattern; a bulk update of 174 records with a
        // database error each would otherwise be tens of KB in tl_log and the bridge.
        $more = \count($reasons) - self::MAX_REASONS;

        return \sprintf(
            '%d von %d nicht geändert — %s%s',
            (int) ($decoded['failed'] ?? \count($errors)),
            (int) ($decoded['total'] ?? \count($errors)),
            implode('; ', \array_slice($reasons, 0, self::MAX_REASONS)),
            $more > 0 ? \sprintf(' … und %d weitere', $more) : '',
        );
    }

    /**
     * Whether the core marked the failure as a defect (core-bundle v1.3.0).
     *
     * @param array<mixed> $decoded
     */
    public static function isDefect(array $decoded): bool
    {
        if (isset($decoded['exception'])) {
            return true;
        }

        foreach (\is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [] as $error) {
            if (\is_array($error) && isset($error['exception'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hook for subclasses to filter the decoded payload before it's wrapped.
     * Default: no-op. Used by MetaTool to strip sensitive DCA fields.
     *
     * @param array<string, mixed> $decoded
     */
    protected function postProcessDecoded(array &$decoded, string $toolName): void
    {
    }

    /**
     * Walk the decoded payload and truncate every string longer than the cap.
     * Numeric IDs / timestamps stay untouched (not strings). Fields whose name
     * suggests a stable identifier (id, alias, type, …) are exempt because
     * truncating them would break references the agent passes back later.
     *
     * @template T
     * @param T $value
     * @return T
     */
    private function truncateStrings(mixed $value, ?string $key = null): mixed
    {
        if (\is_string($value)) {
            if (null !== $key && \in_array($key, self::IDENTIFIER_FIELDS, true)) {
                return $value;
            }
            if (\strlen($value) <= self::FIELD_TRUNCATION_BYTES) {
                return $value;
            }
            return mb_strcut($value, 0, self::FIELD_TRUNCATION_BYTES) . '…[truncated]';
        }
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->truncateStrings($v, \is_string($k) ? $k : null);
            }
            return $out;
        }
        return $value;
    }

    /**
     * Field names whose values must never be truncated — they are stable identifiers
     * the agent passes back into subsequent tool calls.
     */
    private const IDENTIFIER_FIELDS = [
        'id', 'pid', 'uuid', 'alias', 'type', 'ptable', 'inColumn', 'table',
        'status', 'tstamp', 'dateAdded',
    ];

    /**
     * Allow-list of writable fields for update/create operations on this tool.
     * MUST be overridden by tools that expose update or create operations.
     * Reasoning: the underlying core-bundle commands write any field on the model
     * (intentional — operator trust via CLI). The agent-facing tool layer must
     * filter this strictly because backend editors are not equally trusted.
     *
     * Fields deliberately NOT in default lists across all tools (to avoid
     * privilege escalation or audit-trail tampering):
     * `id`, `tstamp`, `pid`, `sorting`, `versionId`, `chmod`, `cuser`, `cgroup`,
     * `includeChmod`, `author`, `start`, `stop` and any DCA `hideInput`/`encrypt` field.
     *
     * @return list<string> empty list = no field writes allowed via this tool
     */
    protected function allowedFields(): array
    {
        return [];
    }

    /**
     * Build --set field=value array from an associative payload.
     * Filters against allowedFields() and rejects values containing control chars,
     * NULs or newlines (which could confuse the underlying ArrayInput).
     *
     * @param array<string, scalar|null> $fields
     * @param string                     $table  checked against the user's field rights
     * @return list<string>
     * @throws ToolAccessDeniedException on disallowed field name, or a field the user has no right to
     * @throws ToolExecutionException    on invalid value (control chars, NUL, newline)
     */
    protected function buildSetOptions(array $fields, string $table): array
    {
        $allowed = $this->allowedFields();

        // The agent's JSON-schema view of `array $fields` is ambiguous: depending
        // on how symfony/ai translates the PHP type, Claude sometimes sends an
        // object like `{"headline": "x"}` and sometimes a list of pair-objects
        // like `[{"name": "headline", "value": "x"}]` or `[{"key": ..., "value": ...}]`,
        // or even a flat list `[{"headline": "x"}]`. Normalize all variants to
        // a single associative array so the rest of this method stays simple.
        // writeFields() also folds a headline's level into the headline (v0.19.2).
        $fields     = $this->writeFields($fields, $table);
        $unitFields = $this->unitFields($table);

        $out = [];
        foreach ($fields as $key => $value) {
            if (null === $value) {
                continue;
            }
            if (!\in_array($key, $allowed, true) && !\in_array(self::unitBase((string) $key, $unitFields), $allowed, true)) {
                throw new ToolAccessDeniedException(
                    \sprintf('Feld "%s" ist für dieses Tool nicht zum Schreiben freigegeben.', $key)
                    . ('unit' === $key && [] !== $unitFields ? \sprintf(' Die Ebene einer Überschrift gehört zu ihrem Feld: "%s_unit" oder als JSON {"unit":…,"value":…} im Feld selbst.', $unitFields[0]) : '')
                );
            }
            $stringValue = (string) $value;
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $stringValue)) {
                throw new ToolRefusedException(
                    \sprintf('Feld "%s" enthält ungültige Steuerzeichen.', $key)
                );
            }
            $out[] = $key . '=' . $stringValue;
        }

        // The tool's list says which fields the chat may write at all; the user's
        // group says which of them this user may (v0.17.0, see FieldPermission).
        // A level needs the right on its headline, as in the back end's form.
        $this->assertFieldsWritable($table, array_values(array_unique(array_map(
            static fn (int|string $key): string => self::unitBase((string) $key, $unitFields) ?? (string) $key,
            array_keys(array_filter($fields, static fn ($value): bool => null !== $value)),
        ))));

        return $out;
    }

    /**
     * A `fields` or `record` parameter as the model sends it: a list, an object, or the
     * same as JSON text (v0.19.3). The text form came as a TypeError the model had to be
     * told about and try again — 11 of 206 calls in the chat runner's first
     * runs, a full extra round trip each. `record_list` and `ext_run` took it all
     * along. Text that is not JSON goes back to the model as its mistake.
     *
     * @param array<int|string, mixed>|string $value
     *
     * @return array<int|string, mixed>
     *
     * @throws ToolCallMistakeException
     */
    protected static function payload(array|string $value, string $parameter): array
    {
        if (\is_array($value)) {
            return $value;
        }
        if ('' === trim($value)) {
            return [];
        }
        $decoded = json_decode($value, true);
        if (\is_array($decoded)) {
            return $decoded;
        }

        throw new ToolCallMistakeException(\sprintf('`%s` takes field names and their values, as a list (["title", "Neu"]) or an object ({"title": "Neu"}); the text sent is neither.', $parameter));
    }

    /**
     * The write payload as the core command takes it (v0.19.2): normalized, a level the
     * model sent on its own folded into its `inputUnit` field, a `{unit, value}` object as
     * the JSON string the core reads.
     *
     * symfony/ai turns `array<string, scalar|null> $fields` into a list of scalars, so a
     * model that wants to set a headline's level has no object to put it in. In one chat
     * it sent the level as a field of its own, and the chat answered
     * `Feld "unit" ist für dieses Tool nicht zum Schreiben freigegeben`. The core takes
     * `headline_unit` for exactly this; a bare `unit` (and a bare `value` with it) goes
     * there when only one `inputUnit` field can be meant.
     *
     * Idempotent: buildSetOptions() runs it again on what a tool already passed through it.
     *
     * @param array<int|string, mixed> $fields
     *
     * @return array<string, scalar|null>
     *
     * @throws ToolRefusedException for an object that is not a `{unit, value}` pair
     */
    protected function writeFields(array $fields, string $table): array
    {
        $fields = self::normalizeFieldsPayload($fields);

        foreach ($fields as $key => $value) {
            if (\is_array($value)) {
                // Only a field with a unit takes a pair and only a list field a list; any
                // other object would reach the command as the text "Array" (until v0.19.1).
                $fields[$key] = match (true) {
                    !array_is_list($value) => self::unitPairJson((string) $key, $value, \in_array($key, $this->unitFields($table), true)),
                    \in_array($key, $this->fieldPermission->listFields($table), true) => self::listJson((string) $key, $value),
                    default => throw new ToolRefusedException(\sprintf('Feld "%s" erwartet einen einfachen Wert, keine Liste. Es wurde nichts geschrieben.', $key)),
                };
            }
        }

        $allowed = $this->allowedFields();
        $target  = \array_key_exists('unit', $fields) && !\in_array('unit', $allowed, true)
            ? $this->unitTarget($fields, $table)
            : null;

        if (null !== $target && !\array_key_exists($target . '_unit', $fields)) {
            $fields[$target . '_unit'] = $fields['unit'];
            unset($fields['unit']);

            // `{"unit": "h2", "value": "Titel"}` as the whole payload: the headline itself.
            if (\array_key_exists('value', $fields) && !\in_array('value', $allowed, true) && !\array_key_exists($target, $fields)) {
                $fields[$target] = $fields['value'];
                unset($fields['value']);
            }
        }

        /** @var array<string, scalar|null> $fields */
        return $fields;
    }

    /**
     * The `inputUnit` fields this tool may write.
     *
     * @return list<string>
     */
    private function unitFields(string $table): array
    {
        $allowed = $this->allowedFields();

        return [] === $allowed
            ? []
            : array_values(array_intersect($allowed, $this->fieldPermission->inputUnitFields($table)));
    }

    /**
     * The field a bare `unit` belongs to: the one `inputUnit` field the payload sets, or
     * the tool's only one when the payload sets none (a level changed on its own).
     *
     * @param array<string, mixed> $fields
     */
    private function unitTarget(array $fields, string $table): ?string
    {
        $candidates = $this->unitFields($table);
        $sent = array_values(array_filter($candidates, static fn (string $field): bool => \array_key_exists($field, $fields)));

        return match (true) {
            1 === \count($sent)                            => $sent[0],
            [] === $sent && 1 === \count($candidates)      => $candidates[0],
            default                                        => null,
        };
    }

    /**
     * `headline` for `headline_unit` when `headline` is one of the given fields, else null.
     *
     * @param list<string> $unitFields
     */
    private static function unitBase(string $key, array $unitFields): ?string
    {
        if (!str_ends_with($key, '_unit')) {
            return null;
        }

        $base = substr($key, 0, -5);

        return \in_array($base, $unitFields, true) ? $base : null;
    }

    /**
     * A list of plain values as the JSON text the core turns into Contao's serialized
     * list, every entry a string as the back end's widgets submit them.
     *
     * @param list<mixed> $value
     *
     * @throws ToolRefusedException when an entry is not a plain value
     */
    private static function listJson(string $key, array $value): string
    {
        foreach ($value as $entry) {
            if (!(\is_scalar($entry) || null === $entry)) {
                throw new ToolRefusedException(\sprintf('Feld "%s" erwartet eine Liste einfacher Werte, z. B. ["800","","proportional"]. Es wurde nichts geschrieben.', $key));
            }
        }

        return json_encode(array_map(static fn (mixed $entry): string => (string) $entry, $value), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<mixed> $value
     *
     * @throws ToolRefusedException when the field takes no unit, or it is not a `{unit, value}` pair of plain values
     */
    private static function unitPairJson(string $key, array $value, bool $takesUnit): string
    {
        $text = $value['value'] ?? null;
        $unit = $value['unit'] ?? null;

        if (!$takesUnit || !\array_key_exists('value', $value) || [] !== array_diff(array_keys($value), ['value', 'unit'])
            || !(\is_scalar($text) || null === $text) || !(\is_scalar($unit) || null === $unit)) {
            throw new ToolRefusedException(\sprintf(
                'Feld "%s" erwartet einen einfachen Wert, kein Objekt. Nur eine Überschrift nimmt {"unit":…,"value":…}. Es wurde nichts geschrieben.',
                $key,
            ));
        }

        $pair = ['value' => (string) $text];
        if (null !== $unit && '' !== (string) $unit) {
            $pair['unit'] = (string) $unit;
        }

        return json_encode($pair, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Normalize a field-mapping payload from Claude. Public so any tool that
     * accepts an `array` parameter (record_list filters, *Tool::update fields)
     * can share the same coercion — Claude sends the same family of shapes
     * regardless of which tool, and the JSON-schema view of `array` doesn't
     * disambiguate object-vs-list-of-pairs.
     *
     * Accepts:
     *   - {"field": "value"} (already associative)
     *   - [{"name": "f", "value": "v"}, …]
     *   - [{"key":  "f", "value": "v"}, …]
     *   - [{"f": "v"}, …]
     *   - ["f", "v", "f2", "v2"] (alternating, observed live)
     *
     * A value stays what the model sent, an object included: the callers decide what
     * an object means (writeFields() since v0.19.2). Until then this said scalar|null,
     * and an object went on as the text "Array".
     *
     * @param array<int|string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function normalizeFieldsPayload(array $fields): array
    {
        if ([] === $fields) {
            return [];
        }
        // Already associative? Detect by checking for at least one non-int key.
        foreach (array_keys($fields) as $k) {
            if (!\is_int($k)) {
                /** @var array<string, mixed> $fields */
                return $fields;
            }
        }
        // List form. Walk entries and merge.
        $out = [];

        // Special case: flat list of even length, all scalar — alternating
        // [name, value, name, value, …]. Claude has been observed sending this
        // for `array $fields` parameters because the JSON-schema view of the
        // PHP `array` type is generic.
        $allScalar = true;
        foreach ($fields as $entry) {
            if (!\is_scalar($entry) && null !== $entry) {
                $allScalar = false;
                break;
            }
        }
        // Special case: list of "field=value" strings. Observed shape on
        // record_list filter parameters — Claude saw "e.g. pid=5" in the
        // tool description and serialized the filters as the same syntax
        // the underlying CLI command consumes. Convert each entry by
        // splitting on the first '=' and skipping malformed ones.
        if ($allScalar) {
            $allEqualPairs = true;
            foreach ($fields as $entry) {
                if (!\is_string($entry) || false === strpos($entry, '=')) {
                    $allEqualPairs = false;
                    break;
                }
            }
            if ($allEqualPairs) {
                foreach ($fields as $entry) {
                    [$k, $v] = explode('=', (string) $entry, 2);
                    $k = trim($k);
                    if ('' !== $k) {
                        $out[$k] = $v;
                    }
                }
                return $out;
            }
        }

        if ($allScalar && 0 === \count($fields) % 2) {
            $values = array_values($fields);
            for ($i = 0; $i < \count($values); $i += 2) {
                $name = (string) $values[$i];
                if ('' === $name) {
                    continue;
                }
                $out[$name] = $values[$i + 1];
            }
            return $out;
        }

        foreach ($fields as $entry) {
            if (\is_array($entry)) {
                if (\array_key_exists('name', $entry) && \array_key_exists('value', $entry)) {
                    // [{"name": "headline", "value": "..."}]
                    $out[(string) $entry['name']] = $entry['value'];
                    continue;
                }
                if (\array_key_exists('key', $entry) && \array_key_exists('value', $entry)) {
                    // [{"key": "headline", "value": "..."}]
                    $out[(string) $entry['key']] = $entry['value'];
                    continue;
                }
                if (1 === \count($entry)) {
                    // [{"headline": "..."}]
                    $k = array_key_first($entry);
                    $out[(string) $k] = $entry[$k];
                    continue;
                }
                // Multi-key associative inside the list — merge as-is.
                foreach ($entry as $k => $v) {
                    if (\is_string($k)) {
                        $out[$k] = $v;
                    }
                }
            }
            // Bare scalars in a list are ignored — there is no meaningful
            // mapping to a field name. The down-stream allow-list check on
            // unknown keys would have rejected them anyway.
        }
        return $out;
    }

    /**
     * @throws ToolAccessDeniedException
     */
    /**
     * Tables an editor may survey, and the back-end module each one requires.
     *
     * 🎯 Until 2026-09-02 this lived in TWO places: `RecordListTool::ALLOWED_TABLES`
     * plus `TABLE_MODULE`, and `MetaTool::ALLOWED_DCA_TABLES`. The comment there read,
     * word for word, *"same allow-list as MetaTool::ALLOWED_DCA_TABLES"* — a duplicate
     * that knew it was one and stayed one anyway. They are merged, because two lists
     * diverge sooner or later and nobody notices which one is right.
     *
     * @var array<string, string>
     */
    protected const TABLE_MODULE = [
        'tl_news'            => 'news',
        'tl_news_archive'    => 'news',
        'tl_page'            => 'page',
        'tl_article'         => 'article',
        'tl_content'         => 'article',
        'tl_calendar'        => 'calendar',
        'tl_calendar_events' => 'calendar',
        'tl_faq'             => 'faq',
        'tl_faq_category'    => 'faq',
        'tl_files'           => 'files',
        'tl_image_size'      => 'themes',
        'tl_image_size_item' => 'themes',
    ];

    /**
     * Module check for a table. Admins pass.
     *
     * `dca_schema` promised in its own tool description *"a Contao table the current
     * user has module access to"* and checked only the table allow-list. The third
     * case of the same pattern in one day — a description that promises a protection
     * the code does not provide.
     */
    protected function assertModuleAccessForTable(string $table): void
    {
        $user = $this->requireBackendUser();

        if ($user->isAdmin) {
            return;
        }

        $module = self::TABLE_MODULE[$table] ?? null;

        if (null === $module) {
            throw new ToolAccessDeniedException(
                \sprintf('Tabelle "%s" hat kein zugeordnetes Backend-Modul.', $table)
            );
        }

        if (!\in_array($module, (array) ($user->modules ?? []), true)) {
            throw new ToolAccessDeniedException(
                \sprintf('Kein Zugriff auf das Backend-Modul "%s".', $module)
            );
        }
    }

    protected function assertAccess(string $toolName): void
    {
        $user = $this->getCurrentBackendUser();
        if (null === $user) {
            throw new ToolAccessDeniedException('Keine aktive Backend-Session.');
        }
        $this->accessChecker->assertCanUseTool($user, $toolName);
    }

    /**
     * Optional acting-user override for stateless bridge calls.
     * The CLI-Bridge controller authenticates via Bearer token outside the
     * contao_backend firewall, so TokenChecker::hasBackendUser() returns
     * false (firewall-context mismatch). The bridge sets the resolved user
     * here directly; getCurrentBackendUser() prefers it over the session
     * lookup. Cleared after the tool call (try/finally in the controller).
     */
    protected ?BackendUser $actingUserOverride = null;

    public function setActingUserOverride(?BackendUser $user): void
    {
        $this->actingUserOverride = $user;
    }

    protected function getCurrentBackendUser(): ?BackendUser
    {
        if (null !== $this->actingUserOverride) {
            return $this->actingUserOverride;
        }

        if (!$this->tokenChecker->hasBackendUser()) {
            return null;
        }

        $user = BackendUser::getInstance();
        return $user instanceof BackendUser ? $user : null;
    }
}
