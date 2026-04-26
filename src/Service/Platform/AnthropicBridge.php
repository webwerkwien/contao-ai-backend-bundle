<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Platform;

use Symfony\AI\Platform\Bridge\Anthropic\PlatformFactory;
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
        return PlatformFactory::create($apiKey);
    }
}
