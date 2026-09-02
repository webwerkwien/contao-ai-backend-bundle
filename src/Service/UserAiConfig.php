<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service;

use Contao\BackendUser;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformRegistry;

class UserAiConfig
{
    private const DEFAULT_PLATFORM = 'anthropic';

    public function __construct(
        private readonly PlatformRegistry $registry,
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
     * L-2: platform string is re-validated. A stray DB value (manual edit, schema
     * drift, partial migration) cannot make us hand off to a non-existent bridge
     * later. Since 2026-09-02 the check asks {@see PlatformRegistry} instead of a
     * hard-coded pair — the list is derived from the installed bridges, so a
     * provider added by `composer require` validates without a code change, and
     * one whose package was *removed* stops validating on the same day rather
     * than failing later inside the factory.
     */
    public function getForUser(BackendUser $user): UserAiConfigDto
    {
        $platform = (string) ($user->ai_platform ?? '');
        $apiKey   = (string) ($user->ai_api_key ?? '');
        $baseUrl  = trim((string) ($user->ai_base_url ?? ''));
        $model    = trim((string) ($user->ai_model ?? ''));

        if ('' === $platform || !$this->registry->has($platform)) {
            if ('' !== $platform) {
                $this->logger->warning('contao-ai-backend: stored AI platform is not installed, falling back', [
                    'username'  => (string) ($user->username ?? '(unknown)'),
                    'stored'    => $platform,
                    'available' => implode(', ', array_keys($this->registry->all())),
                ]);
            }

            $platform = $this->registry->has(self::DEFAULT_PLATFORM)
                ? self::DEFAULT_PLATFORM
                : (array_key_first($this->registry->all()) ?? self::DEFAULT_PLATFORM);
        }

        // A self-hosted provider legitimately has no key, so the missing-key
        // note would be noise there — and worse, it would train the reader to
        // ignore it for the cases where it matters.
        if ('' === $apiKey && $this->registry->has($platform) && $this->registry->get($platform)->apiKeyRequired) {
            $this->logger->debug('contao-ai-backend: backend user opened the chat without an AI API key', [
                'username' => (string) ($user->username ?? '(unknown)'),
                'platform' => $platform,
            ]);
        }

        return new UserAiConfigDto(
            $platform,
            $apiKey,
            '' === $baseUrl ? null : $baseUrl,
            '' === $model ? null : $model,
        );
    }
}
