<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Platform;

use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\AI\Platform\Bridge\Anthropic\ModelCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;

class AnthropicBridge implements PlatformBridgeInterface
{
    /**
     * What a user may pick in the chat when the installation configures nothing
     * (`contao_ai_backend.models.anthropic`); the first is the default (v0.20.0).
     * Until then the default was claude-sonnet-4-5-20250929, fixed here.
     */
    public const MODELS = ['claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-opus-5-5'];

    /**
     * Current models symfony/ai's catalog does not list yet (v0.14.1 ends at
     * claude-sonnet-4-6, claude-opus-4-7, claude-fable-5-1). A model outside the catalog
     * is refused with ModelNotFoundException, so without this `claude-sonnet-5-5` in a
     * profile failed. Same capabilities as claude-sonnet-4-6. Drop an entry once the
     * catalog has it — the status routine compares the two.
     */
    public const ADDITIONAL_MODELS = ['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-haiku-5-5'];

    public function __construct(
        private readonly string $defaultModel = self::MODELS[0],
    ) {
    }

    public function getName(): string
    {
        return 'anthropic';
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel;
    }

    /**
     * @return list<string>
     */
    public function getModels(): array
    {
        return self::MODELS;
    }

    public function createPlatform(#[\SensitiveParameter] string $apiKey): PlatformInterface
    {
        // symfony/ai 0.13: one `Factory` per bridge replaced `PlatformFactory`.
        // `createPlatform()` is the standalone case; `createProvider()` exists for
        // composing several providers into one Platform — that is the door to
        // offering more than two providers without a second bridge class each.
        return Factory::createPlatform($apiKey, ...$this->factoryArguments());
    }

    /**
     * Added to the package factory's arguments where symfony/ai-anthropic-platform builds
     * the platform (PlatformRegistry::bridgeFactoryArguments()) — the usual case.
     *
     * @return array{modelCatalog: ModelCatalog}
     */
    public function factoryArguments(): array
    {
        return ['modelCatalog' => new ModelCatalog(self::additionalModels())];
    }

    /**
     * @return array<string, array{class: string, capabilities: list<Capability>}>
     */
    public static function additionalModels(): array
    {
        $capabilities = [
            Capability::INPUT_MESSAGES,
            Capability::INPUT_IMAGE,
            Capability::OUTPUT_TEXT,
            Capability::OUTPUT_STREAMING,
            Capability::OUTPUT_STRUCTURED,
            Capability::THINKING,
            Capability::TOOL_CALLING,
        ];

        return array_fill_keys(self::ADDITIONAL_MODELS, ['class' => Claude::class, 'capabilities' => $capabilities]);
    }
}
