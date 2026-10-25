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
     * Previous implementation cached the DTO in a `\WeakMap<BackendUser, …>`.
     * Under long-running runtimes (Octane, Workerman) and even within a single FPM
     * request that rotates the key in another tab, the cache returned the *old* DTO
     * because the BackendUser instance was the same. Reading directly from the user
     * model removes the TOCTOU window entirely — the model is the source of truth.
     *
     * Platform string is re-validated. A stray DB value (manual edit, schema
     * drift, partial migration) cannot make us hand off to a non-existent bridge
     * later. Since 2026-09-02 the check asks {@see PlatformRegistry} instead of a
     * hard-coded pair — the list is derived from the installed bridges, so a
     * provider added by `composer require` is accepted without a code change.
     *
     * A stored provider that is *not* installed is passed through unchanged and
     * only logged here. Refusing it is `PlatformRegistry::get()`'s job, which
     * names the provider and lists the available ones. This class reads; it does
     * not decide — and above all it does not substitute (see below).
     */
    public function getForUser(BackendUser $user): UserAiConfigDto
    {
        $platform = (string) ($user->ai_platform ?? '');
        $apiKey   = (string) ($user->ai_api_key ?? '');
        $baseUrl  = trim((string) ($user->ai_base_url ?? ''));
        $model    = trim((string) ($user->ai_model ?? ''));

        // 🔴 Until 2026-09-02 this fell back to `anthropic` as soon as the stored
        // platform was not installed — **keeping the key**. A
        // `composer remove symfony/ai-mistral-platform` would thus have been
        // enough to send a user's Mistral key to api.anthropic.com on the next
        // chat. Reproduced, not assumed.
        //
        // 🎯 The rule that does not allow it again:
        //    **A stored key belongs to exactly the provider the user chose. If the
        //    provider changes, the key is wrong.**
        //
        // So nothing is replaced here any more. An unknown value is passed on
        // unchanged; `PlatformRegistry::get()` then throws an AiConfigException
        // that names the provider and lists the available ones. This class reads,
        // it does not decide.
        if ('' !== $platform && !$this->registry->has($platform)) {
            $this->logger->warning('contao-ai-backend: stored AI platform is not installed', [
                'username'  => (string) ($user->username ?? '(unknown)'),
                'stored'    => $platform,
                'available' => implode(', ', array_keys($this->registry->all())),
            ]);
        }

        // A default is harmless only where nothing can be mixed up: no provider
        // chosen AND no key stored. If there is a key but no provider was chosen,
        // the field stays empty — guessing would mean making the same mistake in
        // another place.
        if ('' === $platform && '' === $apiKey && $this->registry->has(self::DEFAULT_PLATFORM)) {
            $platform = self::DEFAULT_PLATFORM;
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
