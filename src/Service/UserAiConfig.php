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
     * provider added by `composer require` is accepted without a code change.
     *
     * A stored provider that is *not* installed is passed through unchanged and
     * only logged here. Refusing it is `PlatformRegistry::get()`'s job, which
     * names the provider and lists the available ones. This class reads; it does
     * not decide — and above all it does not substitute (see C-1 below).
     */
    public function getForUser(BackendUser $user): UserAiConfigDto
    {
        $platform = (string) ($user->ai_platform ?? '');
        $apiKey   = (string) ($user->ai_api_key ?? '');
        $baseUrl  = trim((string) ($user->ai_base_url ?? ''));
        $model    = trim((string) ($user->ai_model ?? ''));

        // 🔴 C-1, 2026-09-02: hier stand ein Rückfall auf `anthropic`, sobald die
        // gespeicherte Plattform nicht installiert war — **unter Beibehaltung des
        // Schlüssels**. Ein `composer remove symfony/ai-mistral-platform` hätte
        // damit gereicht, um den Mistral-Schlüssel eines Benutzers beim nächsten
        // Chat an api.anthropic.com zu schicken. Reproduziert, nicht vermutet.
        //
        // 🎯 Die Regel, die das nicht wieder zulässt:
        //    **Ein gespeicherter Schlüssel gehört zu genau dem Anbieter, den der
        //    Benutzer gewählt hat. Wechselt der Anbieter, ist der Schlüssel falsch.**
        //
        // Deshalb wird hier nichts mehr ersetzt. Ein unbekannter Wert wird
        // unverändert weitergereicht; `PlatformRegistry::get()` wirft dann eine
        // AiConfigException, die den Anbieter benennt und die verfügbaren
        // aufzählt. Diese Klasse liest, sie entscheidet nicht.
        if ('' !== $platform && !$this->registry->has($platform)) {
            $this->logger->warning('contao-ai-backend: stored AI platform is not installed', [
                'username'  => (string) ($user->username ?? '(unknown)'),
                'stored'    => $platform,
                'available' => implode(', ', array_keys($this->registry->all())),
            ]);
        }

        // Eine Vorgabe ist nur dort unbedenklich, wo nichts zu verwechseln ist:
        // kein Anbieter gewählt UND kein Schlüssel hinterlegt. Liegt ein Schlüssel
        // vor, ohne dass ein Anbieter gewählt wurde, bleibt das Feld leer — raten
        // hieße, denselben Fehler an anderer Stelle zu machen.
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
