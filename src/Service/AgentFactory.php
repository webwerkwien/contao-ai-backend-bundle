<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service;

use Contao\BackendUser;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\AiConfigException;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformRegistry;
use Webwerkwien\ContaoAiBackendBundle\Tool\AbstractCoreCommandTool;

class AgentFactory
{
    /**
     * @param iterable<AbstractCoreCommandTool> $tools
     */
    public function __construct(
        #[TaggedIterator('contao_ai_backend.tool')]
        private readonly iterable $tools,
        private readonly PlatformRegistry $platforms,
        private readonly UserAiConfig $userConfig,
        private readonly SystemPromptProvider $promptProvider,
        private readonly ToolAccessChecker $accessChecker,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function createForUser(BackendUser $user, ?string $modelOverride = null): AgentInvocation
    {
        $config     = $this->userConfig->getForUser($user);
        $descriptor = $this->platforms->get($config->platform);

        // Only providers that actually want a key are refused without one.
        // Ollama and LM Studio take a host instead, and rejecting them here
        // would make the self-hosted case — the reason the registry exists —
        // unreachable through the very check meant to protect it.
        if ($descriptor->apiKeyRequired && !$config->hasApiKey()) {
            throw new AiConfigException(\sprintf(
                'Im Benutzerprofil ist kein KI-API-Key für "%s" hinterlegt.',
                $descriptor->label,
            ));
        }

        $platform = $this->platforms->createPlatform(
            $config->platform,
            $config->getApiKey(),
            $config->baseUrl ?? $descriptor->baseUrlDefault,
        );

        $model = $modelOverride ?? $config->model ?? $descriptor->defaultModel;

        if (null === $model || '' === $model) {
            throw new AiConfigException(\sprintf(
                'Für "%s" ist kein Modell hinterlegt. Trage im Benutzerprofil unter "Modell" eines ein.',
                $descriptor->label,
            ));
        }

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
