<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Platform;

use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('contao_ai_backend.platform_bridge')]
interface PlatformBridgeInterface
{
    /**
     * Stable identifier matching the value stored in tl_user.ai_platform.
     */
    public function getName(): string;

    /**
     * Default model identifier for this platform when the user doesn't override.
     */
    public function getDefaultModel(): string;

    /**
     * Build a fresh PlatformInterface instance bound to the given API key.
     */
    public function createPlatform(#[\SensitiveParameter] string $apiKey): PlatformInterface;
}
