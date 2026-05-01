<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service;

use Contao\BackendUser;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\AiConfigException;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformBridgeInterface;
use Webwerkwien\ContaoAiBackendBundle\Tool\AbstractCoreCommandTool;

class AgentFactory
{
    /**
     * @param iterable<AbstractCoreCommandTool>     $tools
     * @param iterable<PlatformBridgeInterface>     $platformBridges
     */
    public function __construct(
        #[TaggedIterator('contao_ai_backend.tool')]
        private readonly iterable $tools,
        #[TaggedIterator('contao_ai_backend.platform_bridge')]
        private readonly iterable $platformBridges,
        private readonly UserAiConfig $userConfig,
        private readonly SystemPromptProvider $promptProvider,
        private readonly ToolAccessChecker $accessChecker,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function createForUser(BackendUser $user, ?string $modelOverride = null): AgentInvocation
    {
        $config = $this->userConfig->getForUser($user);

        if (!$config->hasApiKey()) {
            throw new AiConfigException('Im Benutzerprofil ist kein KI-API-Key hinterlegt.');
        }

        $bridge = $this->resolveBridge($config->platform);
        $platform = $bridge->createPlatform($config->getApiKey());
        $model = $modelOverride ?? $bridge->getDefaultModel();

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
        $processor = new AgentProcessor($toolbox);
        $agent    = new Agent($platform, $model, [$processor], [$processor]);

        $allowedToolNames = $this->accessChecker->listAllowedTools($user);

        return new AgentInvocation(
            $agent,
            $this->promptProvider->forUser($user, $allowedToolNames),
            $model,
            $allowedToolNames,
        );
    }

    private function resolveBridge(string $platform): PlatformBridgeInterface
    {
        foreach ($this->platformBridges as $bridge) {
            if ($bridge->getName() === $platform) {
                return $bridge;
            }
        }
        throw new AiConfigException(\sprintf('Unbekannte KI-Plattform "%s".', $platform));
    }
}
