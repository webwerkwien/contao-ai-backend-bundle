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
 */
final class UserAiConfigDto
{
    public function __construct(
        public readonly string $platform,
        #[\SensitiveParameter]
        private readonly string $apiKey,
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
        ];
    }
}
