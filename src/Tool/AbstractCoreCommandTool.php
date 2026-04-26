<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;

abstract class AbstractCoreCommandTool
{
    public function __construct(
        protected readonly ToolAccessChecker $accessChecker,
        protected readonly TokenChecker $tokenChecker,
    ) {
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
     * Truncation cap for free-text fields inside tool outputs. Anything beyond gets
     * clipped with a `…[truncated]` suffix so a single bloated DB row cannot blow the
     * context budget — and an embedded prompt-injection payload runs out of room.
     */
    protected const FIELD_TRUNCATION_BYTES = 500;

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
            throw new ToolExecutionException(\sprintf('Tool "%s" fehlgeschlagen: %s', $toolName, $message));
        }

        $decoded = $this->truncateStrings($decoded);
        $this->postProcessDecoded($decoded, $toolName);

        $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
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
                throw new ToolExecutionException(
                    \sprintf('Feld "%s" enthält ungültige Steuerzeichen.', $key)
                );
            }
            $out[] = $key . '=' . $stringValue;
        }
        return $out;
    }

    /**
     * @throws ToolAccessDeniedException
     */
    protected function assertAccess(string $toolName): void
    {
        $user = $this->getCurrentBackendUser();
        if (null === $user) {
            throw new ToolAccessDeniedException('Keine aktive Backend-Session.');
        }
        $this->accessChecker->assertCanUseTool($user, $toolName);
    }

    protected function getCurrentBackendUser(): ?BackendUser
    {
        if (!$this->tokenChecker->hasBackendUser()) {
            return null;
        }

        $user = BackendUser::getInstance();
        return $user instanceof BackendUser ? $user : null;
    }
}
