<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Event\ToolCallFailed;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Webwerkwien\ContaoAiCoreBundle\Service\CredentialMasker;

/**
 * Logs every tool invocation requested by the agent (and its outcome) to the
 * Contao prod log. Lets us audit "did the agent actually call the tool, or did
 * it skip and answer from session history?" — a question the SSE response body
 * alone cannot reliably answer.
 *
 * Argument values are intentionally kept whole; sensitive fields are already
 * stripped one layer up in MetaTool/RecordListTool::postProcessDecoded.
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
            if (\is_array($value)) {
                $arguments[$key] = self::maskArguments($value);
                continue;
            }

            if (\is_string($value)) {
                $arguments[$key] = CredentialMasker::mask($value);
            }
        }

        return $arguments;
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
        $this->logger->warning(sprintf(
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
