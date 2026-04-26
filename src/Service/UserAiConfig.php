<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service;

use Contao\BackendUser;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class UserAiConfig
{
    private const DEFAULT_PLATFORM  = 'anthropic';
    private const ALLOWED_PLATFORMS = ['anthropic', 'openai'];

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * H-4: previous implementation cached the DTO in a `\WeakMap<BackendUser, …>`.
     * Under long-running runtimes (Octane, Workerman) and even within a single FPM
     * request that rotates the key in another tab, the cache returned the *old* DTO
     * because the BackendUser instance was the same. Reading directly from the user
     * model removes the TOCTOU window entirely — the model is the source of truth.
     *
     * L-2: platform string is re-validated against the option list. A stray DB value
     * (manual edit, schema drift, partial migration) cannot make us hand off to a
     * non-existent bridge later.
     */
    public function getForUser(BackendUser $user): UserAiConfigDto
    {
        $platform = (string) ($user->ai_platform ?? '');
        $apiKey   = (string) ($user->ai_api_key ?? '');

        if ('' === $platform || !\in_array($platform, self::ALLOWED_PLATFORMS, true)) {
            $platform = self::DEFAULT_PLATFORM;
        }

        if ('' === $apiKey) {
            $this->logger->debug('contao-ai-backend: backend user opened the chat without an AI API key', [
                'username' => (string) ($user->username ?? '(unknown)'),
                'platform' => $platform,
            ]);
        }

        return new UserAiConfigDto($platform, $apiKey);
    }
}
