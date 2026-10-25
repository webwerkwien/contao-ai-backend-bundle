<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Single-use, time-bounded staging area for "destructive" tool calls.
 *
 * The Smart-History stub design deliberately strips tool outputs
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
 * - **Turn-bound**: an entry staged in one HTTP request cannot be consumed in
 *   that same request. See below.
 *
 * ## 🔴 Why the turn binding was added
 *
 * Until then the only thing that put a human between question and execution
 * was a sentence in the JSON to the model: *"Frage den User wörtlich. Bei
 * klarem Ja denselben Tool-Aufruf erneut absetzen."*
 *
 * Since symfony/ai 0.13 the agent drives the tool loop itself
 * (`maxToolCalls` 50). So the model could call `page_delete`, read the answer
 * *"pending_confirmation"* and **call again at once in the same run** —
 * `consume()` matched, the deletion ran, the user never saw the question.
 *
 * 🎯 **The gate was a request to the model, not a control** — and the model is
 * exactly the party that can be steered through untrusted record content. A
 * promise whose enforcement lies with the potential attacker is none.
 *
 * One HTTP request is exactly one chat turn. Binding to the request's ID thus
 * enforces what the text only asked for: **between question and execution the
 * human must have sent something.** It does not prove a "yes" — it proves that
 * the question was visible and a human acted after it.
 */
class PendingActionStore
{
    private const SESSION_PREFIX  = 'contao_ai_backend.pending_action.';
    private const DEFAULT_TTL     = 300;
    private const TURN_ATTRIBUTE  = '_contao_ai_backend.turn';

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
            'turn'       => $this->currentTurnId(),
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
        // Not redeemable in the same request. The entry deliberately stays —
        // the next turn must still be able to confirm it.
        if ($this->stagedInCurrentTurn($userId, $tool, $key)) {
            return null;
        }

        $payload = $this->peek($userId, $tool, $key);
        if (null === $payload) {
            return null;
        }
        $session = $this->session();
        $session?->remove($this->sessionKey($userId, $tool, $key));
        return $payload;
    }

    /**
     * True when the pending entry was staged by the request currently running.
     */
    public function stagedInCurrentTurn(int $userId, string $tool, string $key): bool
    {
        $session = $this->session();
        if (null === $session) {
            return false;
        }

        $entry = $session->get($this->sessionKey($userId, $tool, $key));

        if (!\is_array($entry) || !isset($entry['turn'])) {
            return false;
        }

        return $entry['turn'] === $this->currentTurnId();
    }

    /**
     * A value that is stable within one HTTP request and different in the next.
     *
     * Stored on the Request's attribute bag rather than derived from anything
     * the client sends — a turn id a caller could choose would let the same
     * caller pretend to be a new turn.
     */
    private function currentTurnId(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return null;
        }

        if (!$request->attributes->has(self::TURN_ATTRIBUTE)) {
            $request->attributes->set(self::TURN_ATTRIBUTE, bin2hex(random_bytes(8)));
        }

        return (string) $request->attributes->get(self::TURN_ATTRIBUTE);
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
