<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Platform;

use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\AI\Platform\PlatformInterface;

class AnthropicBridge implements PlatformBridgeInterface
{
    public function __construct(
        private readonly string $defaultModel = 'claude-sonnet-4-5-20250929',
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

    public function createPlatform(#[\SensitiveParameter] string $apiKey): PlatformInterface
    {
        // symfony/ai 0.13: one `Factory` per bridge replaced `PlatformFactory`.
        // `createPlatform()` is the standalone case; `createProvider()` exists for
        // composing several providers into one Platform — that is the door to
        // offering more than two providers without a second bridge class each.
        return Factory::createPlatform($apiKey);
    }
}
