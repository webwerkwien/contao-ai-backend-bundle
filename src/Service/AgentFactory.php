<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service;

use Contao\BackendUser;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformResolver;
use Webwerkwien\ContaoAiBackendBundle\Tool\AbstractCoreCommandTool;

class AgentFactory
{
    /**
     * @param iterable<AbstractCoreCommandTool> $tools
     */
    public function __construct(
        #[TaggedIterator('contao_ai_backend.tool')]
        private readonly iterable $tools,
        private readonly PlatformResolver $platforms,
        private readonly SystemPromptProvider $promptProvider,
        private readonly ToolAccessChecker $accessChecker,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function createForUser(BackendUser $user, ?string $modelOverride = null): AgentInvocation
    {
        // Platform, model and the "is this profile usable at all" question all
        // live in PlatformResolver now. They used to live here, and three other
        // call sites answered them differently — see that class's docblock.
        $resolved = $this->platforms->resolve($user, $modelOverride);
        $platform = $resolved->platform;
        $model    = $resolved->model;

        $allowedTools = [];
        foreach ($this->tools as $tool) {
            if ($tool->isAccessibleBy($user)) {
                $allowedTools[] = $tool;
            }
        }

        // Wire EventDispatcher into Toolbox so ToolCallLogger can audit each
        // tool invocation. Without this, a chat that "looks successful" but
        // skipped tool calls (LLM extrapolating from prior outputs) is
        // indistinguishable from a real run in the SSE response alone.
        $toolbox  = new Toolbox(
            tools: $allowedTools,
            logger: $this->logger,
            eventDispatcher: $this->eventDispatcher,
        );
        // symfony/ai 0.13 removed Toolbox\AgentProcessor: tool calling is no
        // longer wired as an input/output processor pair, the Agent drives the
        // loop itself and takes the toolbox directly. maxToolCalls defaults to
        // 50 since 0.10 — bounded by default, which it was not before.
        $agent = new Agent($platform, $model, toolbox: $toolbox);

        $allowedToolNames = $this->accessChecker->listAllowedTools($user);

        return new AgentInvocation(
            $agent,
            $this->promptProvider->forUser($user, $allowedToolNames),
            $model,
            $allowedToolNames,
        );
    }

}
