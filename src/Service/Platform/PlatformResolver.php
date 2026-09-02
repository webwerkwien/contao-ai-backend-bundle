<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Platform;

use Contao\BackendUser;
use Symfony\AI\Platform\PlatformInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\AiConfigException;
use Webwerkwien\ContaoAiBackendBundle\Service\UserAiConfig;

/**
 * The single place that turns a backend user's profile into a usable platform.
 *
 * ## Why this exists
 *
 * On 2026-09-02 the provider list became derived instead of hand-maintained, and
 * `AgentFactory` was rewritten to ask the descriptor whether a key is required.
 * **Three other places asking the same question were left as they were**, and a
 * review found all three:
 *
 * - `RecordRewriteTool` still demanded an API key unconditionally and resolved
 *   the platform through the two hand-written bridge classes — so `record_rewrite`
 *   answered *"Unbekannte KI-Plattform"* for every derived provider and *"kein
 *   API-Key"* for Ollama, while ignoring `ai_model` and `ai_base_url` entirely.
 * - `ChatViewRenderer` gated the whole chat UI on `hasApiKey()`, so a user on a
 *   self-hosted provider — the case the derivation was built for — saw no chat
 *   at all.
 * - `PlatformRegistry::createPlatform()` dropped a stored base URL for providers
 *   whose factory has no such parameter, silently.
 *
 * 🎯 **Three symptoms, one cause: the rule moved and its readers did not.** The
 * answer is not to fix three call sites but to leave only one place that can
 * answer the question at all.
 */
class PlatformResolver
{
    public function __construct(
        private readonly UserAiConfig $userConfig,
        private readonly PlatformRegistry $registry,
    ) {
    }

    /**
     * Everything a caller needs to run an agent for this user.
     *
     * @throws AiConfigException when the profile cannot produce a usable platform
     */
    public function resolve(BackendUser $user, ?string $modelOverride = null): ResolvedPlatform
    {
        $config     = $this->userConfig->getForUser($user);
        $descriptor = $this->registry->get($config->platform);

        $missing = $this->missingRequirement($user);

        if (null !== $missing) {
            throw new AiConfigException($missing);
        }

        $platform = $this->registry->createPlatform(
            $config->platform,
            $config->getApiKey(),
            $config->baseUrl,
        );

        $model = $modelOverride ?? $config->model ?? $descriptor->defaultModel;

        if (null === $model || '' === $model) {
            throw new AiConfigException(\sprintf(
                'Für "%s" ist kein Modell hinterlegt. Trage im Benutzerprofil unter "Modell" eines ein.',
                $descriptor->label,
            ));
        }

        return new ResolvedPlatform($platform, $model, $descriptor);
    }

    /**
     * What keeps this profile from working, in one sentence — or null when it works.
     *
     * Used by the chat view to decide whether to render the UI. It deliberately
     * returns the *reason*: "no API key" is wrong for Ollama and "not configured"
     * tells nobody what to do.
     */
    public function missingRequirement(BackendUser $user): ?string
    {
        $config = $this->userConfig->getForUser($user);

        if ('' === $config->platform) {
            return 'Im Benutzerprofil ist kein KI-Anbieter gewählt.';
        }

        if (!$this->registry->has($config->platform)) {
            return \sprintf(
                'Der gewählte KI-Anbieter "%s" ist auf dieser Installation nicht (mehr) installiert.',
                $config->platform,
            );
        }

        $descriptor = $this->registry->get($config->platform);

        if ($descriptor->apiKeyRequired && !$config->hasApiKey()) {
            return \sprintf('Im Benutzerprofil ist kein API-Schlüssel für "%s" hinterlegt.', $descriptor->label);
        }

        if ($descriptor->baseUrlRequired && !$config->hasBaseUrl()) {
            return \sprintf('Für "%s" fehlt die Endpunkt-Adresse im Benutzerprofil.', $descriptor->label);
        }

        return null;
    }

    public function isUsableBy(BackendUser $user): bool
    {
        return null === $this->missingRequirement($user);
    }
}
