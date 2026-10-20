<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Platform;

use Contao\BackendUser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoAiBackendBundle\Service\UserAiConfig;

/**
 * Which models a user may pick in the chat, and which one is preselected (v0.20.0).
 *
 * The list per platform is the installation's (`contao_ai_backend.models`), else the
 * bridge's own (AnthropicBridge::MODELS, OpenAiBridge::MODELS); the first is the default.
 * A model in the user's profile (`ai_model`) is always among the choices and preselected —
 * it is that user's default. The server takes nothing else from the browser: a model name
 * the page sends is checked here, or anyone could spend money on any model.
 */
class ModelChoice
{
    /**
     * @param array<string, list<string>> $configured platform => models, first = default
     */
    public function __construct(
        private readonly UserAiConfig $userConfig,
        private readonly PlatformRegistry $registry,
        #[Autowire('%contao_ai_backend.models%')]
        private readonly array $configured = [],
    ) {
    }

    /**
     * @return list<string> what the user may pick, the preselected one first
     */
    public function choicesFor(BackendUser $user): array
    {
        $config = $this->userConfig->getForUser($user);
        $models = $this->forPlatform($config->platform);

        if (null !== $config->model && '' !== $config->model) {
            $models = [$config->model, ...array_values(array_diff($models, [$config->model]))];
        }

        return $models;
    }

    public function allows(BackendUser $user, string $model): bool
    {
        return \in_array($model, $this->choicesFor($user), true);
    }

    /**
     * The installation's or the bridge's first model for a platform, null when neither
     * has one (a derived provider: the profile has to name the model).
     */
    public function defaultFor(string $platform): ?string
    {
        return $this->forPlatform($platform)[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function forPlatform(string $platform): array
    {
        if ([] !== ($this->configured[$platform] ?? [])) {
            return array_values(array_unique(array_map('strval', $this->configured[$platform])));
        }

        $descriptor = $this->registry->has($platform) ? $this->registry->get($platform) : null;
        if (null === $descriptor) {
            return [];
        }

        $models = $descriptor->models;
        if ([] === $models && null !== $descriptor->defaultModel && '' !== $descriptor->defaultModel) {
            $models = [$descriptor->defaultModel];
        }

        return $models;
    }
}
