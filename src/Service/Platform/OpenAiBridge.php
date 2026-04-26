<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Platform;

use Symfony\AI\Platform\Bridge\OpenAi\PlatformFactory;
use Symfony\AI\Platform\PlatformInterface;

class OpenAiBridge implements PlatformBridgeInterface
{
    public function __construct(
        private readonly string $defaultModel = 'gpt-4o-mini',
    ) {
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
        return PlatformFactory::create($apiKey);
    }
}
