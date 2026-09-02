<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service;

/**
 * H-7: API key is private with a getter, so casual `dump($config)`/`var_dump`
 * inside a profiler or stack-trace screenshot does not paste the plaintext key
 * across the screen. `\SensitiveParameter` only suppresses display in stack
 * traces of *function arguments*, not in serialized object dumps.
 *
 * The full key is still recoverable via `->getApiKey()` for the bridge that
 * needs it; `__debugInfo()` returns a redacted preview for diagnostics.
 *
 * `baseUrl` joined in 2026-09-02 with the derived platform registry: a
 * self-hosted provider (Ollama, LM Studio) wants a host and no key at all, and
 * the previous shape — one platform string plus one key — could not say that.
 */
final class UserAiConfigDto
{
    public function __construct(
        public readonly string $platform,
        #[\SensitiveParameter]
        private readonly string $apiKey,
        public readonly ?string $baseUrl = null,
        public readonly ?string $model = null,
    ) {
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function hasApiKey(): bool
    {
        return '' !== $this->apiKey;
    }

    public function hasBaseUrl(): bool
    {
        return null !== $this->baseUrl && '' !== $this->baseUrl;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        $preview = '' === $this->apiKey
            ? '(empty)'
            : '***' . mb_substr($this->apiKey, -4);

        return [
            'platform' => $this->platform,
            'apiKey'   => $preview,
            'baseUrl'  => $this->baseUrl ?? '(none)',
            'model'    => $this->model ?? '(default)',
        ];
    }
}
