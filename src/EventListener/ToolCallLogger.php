<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Event\ToolCallFailed;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Agent\Toolbox\Exception\InvalidToolCallArgumentsException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Webwerkwien\ContaoAiBackendBundle\Service\SelfCorrectingToolbox;
use Webwerkwien\ContaoAiCoreBundle\Service\CredentialMasker;

/**
 * Logs every tool invocation requested by the agent (and its outcome) to the
 * Contao prod log. Lets us audit "did the agent actually call the tool, or did
 * it skip and answer from session history?" — a question the SSE response body
 * alone cannot reliably answer.
 *
 * Argument values are kept whole except for what looks secret: key formats
 * (CredentialMasker) and, since v0.14.1, values under names that read like a
 * secret — `password=…`, an `api-key` option (maskArguments()).
 *
 * All events are emitted at WARNING level on purpose: the Contao Monolog
 * handler uses a fingers_crossed activation strategy at WARNING, so anything
 * lower (INFO/NOTICE) gets buffered in memory and discarded once the request
 * ends without triggering an actual warning. Promoting these audit lines to
 * WARNING ensures they always reach disk.
 *
 * The tool name belongs in the message, not only in the context: these entries
 * also reach tl_log, and ContaoTableHandler formats with LineFormatter('%message%')
 * -- the context is dropped there. Without the name in the message the back end
 * showed nothing but "tool requested", which answers no question at all.
 */
class ToolCallLogger implements EventSubscriberInterface
{
    /**
     * Tools (by name) the agent invoked during the current request.
     *
     * Symfony services are singletons within an FPM worker, so this property
     * persists across requests. AiStreamController calls startRequest() at the
     * top of every chat invocation to clear it; getToolNames() is consulted
     * after agent->call() to decide whether the assistant turn should be
     * stubbed in the persisted history (any tool was used) or stored verbatim
     * (purely conversational).
     *
     * @var list<string>
     */
    private array $toolsCalledThisRequest = [];

    public function __construct(
        // Use the contao.general channel so entries hit the same Contao
        // Monolog handler that writes var/logs/prod-YYYY-MM-DD.log; the
        // default app-channel logger is silent in this stack.
        #[Autowire(service: 'monolog.logger.contao.general')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function startRequest(): void
    {
        $this->toolsCalledThisRequest = [];
    }

    /**
     * @return list<string> tool names invoked since the last startRequest()
     */
    public function getToolNames(): array
    {
        return array_values(array_unique($this->toolsCalledThisRequest));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ToolCallRequested::class => 'onRequested',
            ToolCallSucceeded::class => 'onSucceeded',
            ToolCallFailed::class    => 'onFailed',
        ];
    }

    /**
     * 🔴 M-4 (Audit 2026-09-02): `getArguments()` ging vollständig und ungemaskt
     * ins Log. Ein Redakteur, der über ein Update-Werkzeug einen Text mit
     * `sk-proj-…` oder einem Bearer-Token schreibt, hatte den vollständigen Wert
     * dauerhaft im Logfile — auf WARNING-Ebene. Die Maskierung schützte bis
     * dahin nur Fehlermeldungen, nicht die Argumente auf dem Weg dorthin.
     *
     * Ohne bekannten Geheimniswert greift hier nur das Muster-Netz: Der Logger
     * sieht den Werkzeugaufruf, nicht das Benutzerprofil. Das ist eine
     * schwächere Zusicherung als in den Controllern — und soll es sein, denn ein
     * Argument ist Text, den ein Benutzer getippt hat, kein Schlüssel, den wir
     * halten.
     *
     * @param  array<mixed> $arguments
     * @return array<mixed>
     */
    private static function maskArguments(array $arguments): array
    {
        foreach ($arguments as $key => $value) {
            // An option named like a secret (`api-key` in ext_run's options): the
            // whole value goes, whatever it looks like.
            if (\is_string($key) && 1 === preg_match(self::SECRET_NAME, $key) && !\is_array($value)) {
                $arguments[$key] = self::MASK;
                continue;
            }

            if (\is_array($value)) {
                $arguments[$key] = self::maskArguments($value);
                continue;
            }

            if (\is_string($value)) {
                $arguments[$key] = self::maskNamedSecrets(CredentialMasker::mask($value));
            }
        }

        return $arguments;
    }

    private const MASK = '***';

    /**
     * Names that read as a secret (v0.14.1) — the same list core-bundle v1.5.0 uses
     * to blank the tl_log line of contao:ai:run (CommandLineRedactor). Kept here
     * rather than called there: this bundle runs with older core versions too.
     * Over-blanking costs a detail in the log, nothing else.
     */
    private const SECRET_NAME = '/pass(?:word|wort|phrase)|secret|token|api[-_]?key|credential|private[-_]?key/i';

    /**
     * `password=…` and `--api-key …` inside a text — ext_run's `--set` pairs, or
     * options the model handed over as one string. CredentialMasker catches key
     * formats (sk-…, Bearer); a password has none.
     */
    private static function maskNamedSecrets(string $text): string
    {
        $name  = '[\w.-]*(?:pass(?:word|wort|phrase)|secret|token|api[-_]?key|credential|private[-_]?key)[\w.-]*';
        $value = '(?:"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|\S+)';

        // name=value
        $text = (string) preg_replace('/(?<![\w.-])(' . $name . ')=' . $value . '/i', '$1=' . self::MASK, $text);

        // --name value, unless the next word is an option itself
        return (string) preg_replace('/(--' . $name . ')(\s+)(?!-)' . $value . '/i', '$1$2' . self::MASK, $text);
    }

    public function onRequested(ToolCallRequested $event): void
    {
        $call = $event->getToolCall();
        $this->toolsCalledThisRequest[] = $call->getName();
        $this->logger->warning(sprintf('contao-ai-backend tool requested: %s', $call->getName()), [
            'tool'      => $call->getName(),
            'arguments' => self::maskArguments($call->getArguments()),
            'call_id'   => $call->getId(),
        ]);
    }

    /*
     * symfony/ai 0.13 renamed the tool description on these events from
     * `getMetadata()` to `getDefinition()`; it returns a `Tool` and still
     * answers `getName()`.
     *
     * Worth noting how this was found: a check that every imported class still
     * exists said everything was fine — the classes did survive, one method did
     * not. The tests caught it. Class-level existence is not API compatibility.
     */
    public function onSucceeded(ToolCallSucceeded $event): void
    {
        $this->logger->warning(sprintf('contao-ai-backend tool succeeded: %s', $event->getDefinition()->getName()), [
            'tool'    => $event->getDefinition()->getName(),
            'call_id' => $event->getResult()->getToolCall()->getId(),
        ]);
    }

    public function onFailed(ToolCallFailed $event): void
    {
        // Arguments that do not fit the tool are the model's mistake, handed back to
        // it by SelfCorrectingToolbox (v0.12.0) -- not a failure of this system, so
        // they do not belong among the warnings in the system log.
        $exception = $event->getException();
        $level     = $exception instanceof InvalidToolCallArgumentsException
            || SelfCorrectingToolbox::isArgumentTypeError($exception, $event->getDefinition()) ? 'info' : 'warning';

        $this->logger->log($level, sprintf(
            'contao-ai-backend tool failed: %s (%s)',
            $event->getDefinition()->getName(),
            $event->getException()::class,
        ), [
            'tool'      => $event->getDefinition()->getName(),
            'arguments' => $event->getArguments(),
            'exception' => $event->getException()::class,
            'message'   => $event->getException()->getMessage(),
        ]);
    }
}
