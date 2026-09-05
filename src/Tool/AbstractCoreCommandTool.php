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
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
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
     * Per-record permission check (H-9). Verifies the current backend user is
     * allowed to perform $operation on the record identified by $recordId.
     *
     * Default: no-op (read-only meta-tools without per-record semantics).
     * Subclasses MUST override for tools that touch user-content tables
     * (news archives, page mounts, article/content trees) and call Contao's
     * BackendUser::hasAccess() / isAllowed() with the right scope.
     *
     * @param string $operation One of: 'read', 'create', 'update', 'delete', 'publish'
     * @throws ToolAccessDeniedException
     */
    protected function assertRecordAccess(int $recordId, string $operation): void
    {
        // Default: trust the class-level isAccessibleBy() decision.
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
     * is what makes confirmation flows survive the Phase-7 Smart-History
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
        // 🔴 C-1 (Audit 2026-09-02): Ein zweiter Aufruf im SELBEN Turn löst nichts
        // mehr aus. Vorher konnte das Modell hier zweimal hintereinander rufen —
        // die Frage an den Benutzer war nur ein Satz in der `note`, und die
        // Werkzeugschleife treibt seit symfony/ai 0.13 das Modell selbst.
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
     * 🔴 H-1 (Audit 2026-09-02): `JSON_HEX_TAG` kam dazu, und zwar aus einem
     * Grund, der ohne Messung nicht sichtbar ist.
     *
     * Der Vorwurf lautete: Datensatz-Inhalt könne den Sentinel schließen
     * (`</tool_output_data>`) und danach als Anweisung erscheinen. Gemessen war
     * das **nicht** der Fall — `json_encode()` escaped den Schrägstrich zu
     * `<\/tool_output_data>`, der Angriff trug nicht.
     *
     * 🎯 Aber das war **zufälliger Schutz**. Niemand hatte `json_encode` deswegen
     * gewählt, ein später ergänztes `JSON_UNESCAPED_SLASHES` — eine plausible
     * Lesbarkeits-Änderung — hätte ihn lautlos entfernt, und der ÖFFNENDE Tag
     * ging ohnehin durch, weil er keinen Schrägstrich enthält.
     *
     * `JSON_HEX_TAG` macht aus der Zufälligkeit eine Zusage: `<` und `>` werden
     * zu `<`/`>`, im Datenbereich kann also überhaupt kein Tag mehr
     * entstehen. Der Rückweg über `json_decode()` liefert den Originalwert —
     * für das Modell ändert sich nichts, für einen Angreifer alles.
     */
    protected const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG;

    /**
     * H-1: tool outputs are returned as a JSON-encoded string wrapped in a sentinel
     * tag pair. The system prompt instructs the model to treat anything inside the
     * wrapper as untrusted data, mitigating prompt injection via DB content.
     *
     * @param array<string, mixed> $arguments
     */
    protected function runCommand(Command $command, array $arguments, string $toolName): string
    {
        $this->assertAccess($toolName);

        // M-2: stamp the audit trail with the actual Contao backend user. The core
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

        $raw = trim($output->fetch());

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
            $message = $decoded['message'] ?? 'unbekannter Fehler';

            // 🔴 Gefunden am 2026-09-02: hier stand `ToolExecutionException`, und
            // damit wurde eine falsche ID zu HTTP 500. Ein nicht vorhandener
            // Datensatz ist kein Serverfehler — und seit contao-ai-cli v0.15.0
            // bedeutet 500 in `bridge configure --test` ausdrücklich „Token gut,
            // Bridge kaputt". Ein Tippfehler hätte damit eine gesunde Bridge
            // angeklagt.
            //
            // Wir sind an dieser Stelle, weil der Befehl ein wohlgeformtes
            // Fehlerobjekt geliefert hat: er lief, verstand die Anfrage und hat
            // sie abgelehnt. Das ist eine Aussage an den Aufrufer, kein Defekt.
            // Siehe ToolRefusedException für die Grenze und ihren Preis.
            throw new ToolRefusedException(\sprintf('Tool "%s" abgelehnt: %s', $toolName, $message));
        }

        $decoded = $this->truncateStrings($decoded);
        $this->postProcessDecoded($decoded, $toolName);

        $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG);
        if (false === $json) {
            throw new ToolExecutionException(\sprintf('Tool "%s" Ausgabe konnte nicht serialisiert werden.', $toolName));
        }

        return "<tool_output_data tool=\"{$toolName}\">\n{$json}\n</tool_output_data>";
    }

    /**
     * Hook for subclasses to filter the decoded payload before it's wrapped.
     * Default: no-op. Used by MetaTool to strip sensitive DCA fields (H-8).
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
     * @return list<string>
     * @throws ToolAccessDeniedException on disallowed field name
     * @throws ToolExecutionException    on invalid value (control chars, NUL, newline)
     */
    protected function buildSetOptions(array $fields): array
    {
        $allowed = $this->allowedFields();

        // The agent's JSON-schema view of `array $fields` is ambiguous: depending
        // on how symfony/ai translates the PHP type, Claude sometimes sends an
        // object like `{"headline": "x"}` and sometimes a list of pair-objects
        // like `[{"name": "headline", "value": "x"}]` or `[{"key": ..., "value": ...}]`,
        // or even a flat list `[{"headline": "x"}]`. Normalize all variants to
        // a single associative array so the rest of this method stays simple.
        $fields = self::normalizeFieldsPayload($fields);

        $out = [];
        foreach ($fields as $key => $value) {
            if (null === $value) {
                continue;
            }
            if (!\in_array($key, $allowed, true)) {
                throw new ToolAccessDeniedException(
                    \sprintf('Feld "%s" ist für dieses Tool nicht zum Schreiben freigegeben.', $key)
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
        return $out;
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
     * @param array<int|string, mixed> $fields
     * @return array<string, scalar|null>
     */
    public static function normalizeFieldsPayload(array $fields): array
    {
        if ([] === $fields) {
            return [];
        }
        // Already associative? Detect by checking for at least one non-int key.
        foreach (array_keys($fields) as $k) {
            if (!\is_int($k)) {
                /** @var array<string, scalar|null> $fields */
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
     * Tabellen, die ein Redakteur überblicken darf, und das Backend-Modul, das
     * dafür nötig ist.
     *
     * 🎯 Stand bis zum 2026-09-02 an ZWEI Stellen: `RecordListTool::ALLOWED_TABLES`
     * plus `TABLE_MODULE`, und `MetaTool::ALLOWED_DCA_TABLES`. Der Kommentar dort
     * lautete wörtlich *"same allow-list as MetaTool::ALLOWED_DCA_TABLES"* — eine
     * Dublette, die sich selbst kannte und trotzdem eine blieb. Sie sind
     * zusammengelegt, weil zwei Listen sich früher oder später unterscheiden und
     * niemand merkt, welche die richtige ist.
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
    ];

    /**
     * Modul-Prüfung für eine Tabelle. Admins passieren.
     *
     * 🔴 L-1 (Audit 2026-09-02): `dca_schema` versprach in seiner eigenen
     * Werkzeug-Beschreibung *"a Contao table the current user has module access
     * to"* und prüfte nur die Tabellen-Allow-Liste. Dritter Fall desselben
     * Musters an einem Tag — eine Beschreibung, die einen Schutz zusagt, den der
     * Code nicht leistet.
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
     * Phase 10.1: optional acting-user override for stateless bridge calls.
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
