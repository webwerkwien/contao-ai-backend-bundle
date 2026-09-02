<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Platform;

/**
 * What one installed symfony/ai platform bridge needs, read from its own
 * `Factory::createPlatform()` signature rather than from a list we maintain.
 *
 * The measurement this replaces (2026-09-02, eight bridges under symfony/ai
 * 0.13) found no matrix at all — across every bridge the caller-supplied
 * surface is exactly two scalars:
 *
 *     Anthropic    apiKey (required)   baseUrl https://api.anthropic.com
 *     Gemini       apiKey (required)   baseUrl https://generativelanguage.googleapis.com
 *     Mistral      apiKey (required)   baseUrl https://api.mistral.ai
 *     OpenRouter   apiKey (required)   baseUrl https://openrouter.ai/api
 *     OpenAi       apiKey (required)   —
 *     Ollama       —                   endpoint (null)
 *     LmStudio     —                   baseUrl http://localhost:1234
 *     Generic      apiKey (optional)   baseUrl (required)
 *
 * Two details a hand-maintained list would have got silently wrong:
 *
 * - **Ollama calls it `endpoint`, everyone else `baseUrl`.** Same concept, two
 *   names. Reading the parameter hits it; assuming it writes into the void.
 * - **`name` is not a model.** Its default is the bridge's own canonical key
 *   (`anthropic`, `openai`, `ollama`) — the very value already stored in
 *   `tl_user.ai_platform`, which is why deriving the list needs no data
 *   migration.
 */
final class PlatformDescriptor
{
    /**
     * @param class-string|null $factoryClass null when this entry comes from a
     *        hand-written {@see PlatformBridgeInterface} rather than an
     *        installed bridge package — then the bridge builds the platform and
     *        no base URL can be applied.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $factoryClass,
        public readonly string $package,
        public readonly ?string $apiKeyParam = null,
        public readonly bool $apiKeyRequired = false,
        public readonly ?string $baseUrlParam = null,
        public readonly bool $baseUrlRequired = false,
        public readonly ?string $baseUrlDefault = null,
        public readonly ?string $defaultModel = null,
    ) {
    }

    /**
     * Whether the platform is built from the installed package's own factory —
     * the case in which a stored base URL is actually applied.
     */
    public function isDerived(): bool
    {
        return null !== $this->factoryClass;
    }

    public function acceptsApiKey(): bool
    {
        return null !== $this->apiKeyParam;
    }

    public function acceptsBaseUrl(): bool
    {
        return null !== $this->baseUrlParam;
    }

    /**
     * True for the bridges that talk to something the operator runs themselves —
     * Ollama, LM Studio, Docker Model Runner. They are the reason this whole
     * derivation exists: the previous interface flattened every provider onto a
     * single `$apiKey`, so a provider wanting a host instead of a key could not
     * be expressed at all.
     */
    public function isSelfHosted(): bool
    {
        return $this->acceptsBaseUrl() && !$this->apiKeyRequired;
    }
}
