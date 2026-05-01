<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Single-use, time-bounded staging area for "destructive" tool calls.
 *
 * The Smart-History stub design (Phase 7) deliberately strips tool outputs
 * from the persisted assistant turn so the LLM cannot copy stale data on a
 * repeat question. As a side effect, multi-turn confirmation flows
 * ("delete X" → "are you sure?" → "yes" → execute) lose the agent's own
 * pending intent across turns: by turn 3 the assistant only sees its own
 * stub and has no record of which delete was being confirmed.
 *
 * This store gives those flows a separate, server-managed slot. The first
 * tool invocation stages the call here (no DB write yet) and returns a
 * `pending_confirmation` payload to the LLM, which then asks the user. On
 * the next user turn the same tool is invoked again with the same primary
 * argument; this time the staged entry is consumed and the actual write
 * runs.
 *
 * Properties:
 * - Single-use: consume() removes the entry on read.
 * - TTL-bounded: expired entries are treated as absent so a forgotten
 *   confirmation cannot resurface a destructive op an hour later.
 * - Per-user, per-tool, per-key: scoped so two simultaneous flows don't
 *   collide (e.g. the same user staging news_delete and page_delete).
 * - HTTP-only: when no request session is available (CLI, tests), peek()
 *   returns null and the caller falls back to immediate execution.
 */
class PendingActionStore
{
    private const SESSION_PREFIX = 'contao_ai_backend.pending_action.';
    private const DEFAULT_TTL    = 300;

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function stage(int $userId, string $tool, string $key, array $payload, int $ttl = self::DEFAULT_TTL): void
    {
        $session = $this->session();
        if (null === $session) {
            return;
        }
        $session->set($this->sessionKey($userId, $tool, $key), [
            'payload'    => $payload,
            'expires_at' => time() + $ttl,
        ]);
    }

    /**
     * @return array<string, mixed>|null payload if a fresh pending entry
     *   exists; null when missing or expired.
     */
    public function peek(int $userId, string $tool, string $key): ?array
    {
        $session = $this->session();
        if (null === $session) {
            return null;
        }
        $entry = $session->get($this->sessionKey($userId, $tool, $key));
        if (!\is_array($entry) || !isset($entry['payload'], $entry['expires_at'])) {
            return null;
        }
        if ($entry['expires_at'] < time()) {
            $session->remove($this->sessionKey($userId, $tool, $key));
            return null;
        }
        return \is_array($entry['payload']) ? $entry['payload'] : null;
    }

    /**
     * @return array<string, mixed>|null the consumed payload (and removes it)
     *   if a fresh pending entry existed; null otherwise.
     */
    public function consume(int $userId, string $tool, string $key): ?array
    {
        $payload = $this->peek($userId, $tool, $key);
        if (null === $payload) {
            return null;
        }
        $session = $this->session();
        $session?->remove($this->sessionKey($userId, $tool, $key));
        return $payload;
    }

    public function clear(int $userId, string $tool, string $key): void
    {
        $session = $this->session();
        $session?->remove($this->sessionKey($userId, $tool, $key));
    }

    private function sessionKey(int $userId, string $tool, string $key): string
    {
        return self::SESSION_PREFIX . $userId . '.' . $tool . '.' . $key;
    }

    private function session(): ?\Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$request->hasSession()) {
            return null;
        }
        return $request->getSession();
    }
}
