<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Event\ToolCallFailed;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

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
 */
class ToolCallLogger implements EventSubscriberInterface
{
    public function __construct(
        // Use the contao.general channel so entries hit the same Contao
        // Monolog handler that writes var/logs/prod-YYYY-MM-DD.log; the
        // default app-channel logger is silent in this stack.
        #[Autowire(service: 'monolog.logger.contao.general')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ToolCallRequested::class => 'onRequested',
            ToolCallSucceeded::class => 'onSucceeded',
            ToolCallFailed::class    => 'onFailed',
        ];
    }

    public function onRequested(ToolCallRequested $event): void
    {
        $call = $event->getToolCall();
        $this->logger->warning('contao-ai-backend tool requested', [
            'tool'      => $call->getName(),
            'arguments' => $call->getArguments(),
            'call_id'   => $call->getId(),
        ]);
    }

    public function onSucceeded(ToolCallSucceeded $event): void
    {
        $this->logger->warning('contao-ai-backend tool succeeded', [
            'tool'    => $event->getMetadata()->getName(),
            'call_id' => $event->getResult()->getToolCall()->getId(),
        ]);
    }

    public function onFailed(ToolCallFailed $event): void
    {
        $this->logger->warning('contao-ai-backend tool failed', [
            'tool'      => $event->getMetadata()->getName(),
            'arguments' => $event->getArguments(),
            'exception' => $event->getException()::class,
            'message'   => $event->getException()->getMessage(),
        ]);
    }
}
