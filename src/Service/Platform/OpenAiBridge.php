<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Platform;

use Symfony\AI\Platform\Bridge\OpenAi\Factory;
use Symfony\AI\Platform\PlatformInterface;

class OpenAiBridge implements PlatformBridgeInterface
{
    /**
     * What a user may pick in the chat when the installation configures nothing
     * (`contao_ai_backend.models.openai`); the first is the default (v0.20.0). Both are in
     * symfony/ai's catalog with tool calling. Until then the default was gpt-4o-mini.
     */
    public const MODELS = ['gpt-5.5', 'gpt-5.4-mini'];

    public function __construct(
        private readonly string $defaultModel = self::MODELS[0],
    ) {
    }

    /**
     * @return list<string>
     */
    public function getModels(): array
    {
        return self::MODELS;
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel;
    }

    public function createPlatform(#[\SensitiveParameter] string $apiKey): PlatformInterface
    {
        // symfony/ai 0.13: one `Factory` per bridge replaced `PlatformFactory`.
        // `createPlatform()` is the standalone case; `createProvider()` exists for
        // composing several providers into one Platform — that is the door to
        // offering more than two providers without a second bridge class each.
        return Factory::createPlatform($apiKey);
    }
}
