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
 * - **Turn-bound**: an entry staged in one HTTP request cannot be consumed in
 *   that same request. See below.
 *
 * ## 🔴 C-1 (Audit 2026-09-02): warum die Turn-Bindung dazukam
 *
 * Bis dahin war die einzige Instanz, die einen Menschen zwischen Frage und
 * Ausführung schaltete, ein Satz im JSON an das Modell: *„Frage den User
 * wörtlich. Bei klarem Ja denselben Tool-Aufruf erneut absetzen."*
 *
 * Seit symfony/ai 0.13 treibt der Agent die Werkzeugschleife selbst
 * (`maxToolCalls` 50). Das Modell konnte also `page_delete` rufen, die Antwort
 * *„pending_confirmation"* lesen und **im selben Durchlauf sofort erneut
 * rufen** — `consume()` griff, die Löschung lief, der Benutzer sah die Frage
 * nie.
 *
 * 🎯 **Das Gate war eine Bitte an das Modell, keine Kontrolle** — und das
 * Modell ist genau die Instanz, die sich über untrusted Datensatz-Inhalte
 * steuern lässt. Eine Zusicherung, deren Durchsetzung beim potenziellen
 * Angreifer liegt, ist keine.
 *
 * Ein HTTP-Request ist genau ein Chat-Turn. Die Bindung an die Request-Kennung
 * erzwingt damit, was der Text nur erbeten hat: **zwischen Frage und
 * Ausführung muss der Mensch etwas gesendet haben.** Sie beweist kein „Ja" —
 * sie beweist, dass die Frage sichtbar war und ein Mensch danach gehandelt hat.
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
        // C-1: nicht im selben Request einlösbar. Der Eintrag bleibt bewusst
        // stehen — der nächste Turn soll ihn noch bestätigen können.
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
