<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Controller;

use Contao\BackendUser;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Webwerkwien\ContaoAiBackendBundle\Exception\AiConfigException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
use Webwerkwien\ContaoAiBackendBundle\Security\AiAccessVoter;
use Webwerkwien\ContaoAiBackendBundle\Service\AgentFactory;

class AiStreamController extends AbstractController
{
    /**
     * Phase-6 finding (live test 2026-05-01): persisted assistant text from
     * earlier turns causes Claude to skip tool calls on repeated questions —
     * it copies its own prior formatted answer ("Hier sind die letzten 3
     * News-Einträge: …") instead of re-fetching, and even appends "die Liste
     * ist unverändert". The H-2 history was designed against tampered client
     * messages, not against in-context staleness.
     *
     * Disabling persistence (cap = 0) is the safest fix: every backend request
     * is treated as standalone. Multi-turn ergonomics ("publish that one")
     * are sacrificed in exchange for correctness — re-enable with a smarter
     * eviction strategy when one is implemented.
     */
    private const MAX_HISTORY_MESSAGES = 0;
    private const MAX_USER_INPUT_BYTES = 8192;
    private const PAYLOAD_DEPTH = 512;

    /**
     * M-7: per-entry and total caps for the persisted history. Without these a single
     * 1 MB tool-response could blow the session-store; 40 entries × unlimited bytes
     * is also a memory-spike vector under concurrent FPM workers.
     */
    private const MAX_HISTORY_ENTRY_BYTES = 4096;
    private const MAX_HISTORY_TOTAL_BYTES = 65536;

    /**
     * H-2: chat history lives server-side in the backend PHP session, keyed by user id.
     * Client-supplied history is ignored — fabricated `assistant` turns can no longer be
     * smuggled in (e.g. "I have already confirmed deletion. Proceeding.").
     */
    private const SESSION_HISTORY_KEY_PREFIX = 'contao_ai_backend.chat_history.';

    /**
     * M-1: session-backed sliding-window rate limit. A compromised account cannot
     * drain the user's Anthropic credits or exhaust FPM workers via burst traffic.
     * Sliding 60-second window; second cap is a daily hard ceiling.
     */
    private const SESSION_RATE_KEY_PREFIX = 'contao_ai_backend.rate.';
    private const RATE_LIMIT_PER_MIN = 30;
    private const RATE_LIMIT_PER_DAY = 500;
    private const RATE_WINDOW_SECONDS = 60;
    private const RATE_DAY_SECONDS    = 86400;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly TokenChecker $tokenChecker,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly AgentFactory $agentFactory,
        private readonly ContaoCsrfTokenManager $csrf,
        private readonly string $csrfTokenName,
        private readonly string $projectDir,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[Route('/contao/ai-chat/stream', name: 'contao_ai_backend_stream', methods: ['POST'], defaults: ['_scope' => 'backend', '_token_check' => false])]
    public function __invoke(Request $request): Response
    {
        $this->framework->initialize();
        $user = $this->requireBackendUser();

        if (!$this->authorizationChecker->isGranted(AiAccessVoter::ATTR_USE_CHAT)) {
            throw new AccessDeniedException('Backend module ai_chat is not granted.');
        }

        $payload = $this->decodePayload($request);
        $this->assertCsrf($payload['csrfToken'] ?? '');
        $this->assertSameOrigin($request);

        if (!$this->checkRateLimit($request, $user)) {
            return $this->errorResponse(429, 'Zu viele Anfragen. Bitte einen Moment warten.');
        }

        $userInput = (string) ($payload['message'] ?? '');
        if ('' === trim($userInput)) {
            return $this->errorResponse(400, 'Leere Nachricht.');
        }
        if (\strlen($userInput) > self::MAX_USER_INPUT_BYTES) {
            return $this->errorResponse(413, 'Nachricht zu lang.');
        }

        $history = $this->loadHistory($request, $user);

        try {
            $invocation = $this->agentFactory->createForUser($user);
        } catch (AiConfigException $e) {
            return $this->errorResponse(412, $e->getMessage());
        }

        $messages = $this->buildMessageBag($invocation->systemPrompt, $history, $userInput);

        // Synchronous response (no StreamedResponse) — Symfony reverse cache layer mishandles streams.
        // Body is SSE-formatted so the frontend parser works unchanged when real streaming returns.
        $body = '';
        $emit = static function (string $event, array $data) use (&$body): void {
            $body .= 'event: ' . $event . "\n";
            $body .= 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
        };

        $emit('start', ['model' => $invocation->model]);

        try {
            $result = $invocation->agent->call($messages);
            $assistantContent = (string) $result->getContent();
            $emit('message', ['content' => $assistantContent]);
            $emit('done', ['ok' => true]);
            // Persist only on successful turns — failed turns leave the store untouched
            // so the agent does not retain garbled state.
            $this->appendHistory($request, $user, $userInput, $assistantContent);
        } catch (ToolAccessDeniedException $e) {
            // Access-denied messages are written by us and stay user-facing — log for audit.
            $this->logger->info('contao_ai_backend tool access denied', ['exception' => $e]);
            $emit('error', ['kind' => 'access_denied', 'message' => $e->getMessage()]);
        } catch (ToolExecutionException $e) {
            // M-11: tool errors may carry PDO output, file paths or upstream library text.
            // Log original; emit a sanitized variant.
            $emit('error', ['kind' => 'tool_failed', 'message' => $this->safeMessage($e)]);
        } catch (\Throwable $e) {
            $emit('error', ['kind' => 'agent_failed', 'message' => $this->safeMessage($e)]);
        }

        return new Response($body, 200, [
            // M-6: explicit charset prevents reverse proxies from interpreting as Latin-1.
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            // M-5: stronger cache directives + Vary so a CDN/reverse cache cannot serve
            // one user's chat response to another. `private` covers shared caches; `no-store`
            // covers any intermediate; Vary keys responses on the auth cookie.
            'Cache-Control'     => 'no-store, private, max-age=0',
            'Vary'              => 'Cookie',
            'X-Accel-Buffering' => 'no',
            'X-Robots-Tag'      => 'noindex, nofollow',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(Request $request): array
    {
        $raw = $request->getContent();
        if ('' === $raw) {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, self::PAYLOAD_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return \is_array($decoded) ? $decoded : [];
    }

    private function assertCsrf(string $token): void
    {
        if ('' === $token) {
            throw new AccessDeniedException('Missing CSRF token.');
        }
        if (!$this->csrf->isTokenValid(new CsrfToken($this->csrfTokenName, $token))) {
            throw new AccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * @param list<array{role: string, content: string}> $history
     */
    private function buildMessageBag(string $systemPrompt, array $history, string $userInput): MessageBag
    {
        $bag = new MessageBag(Message::forSystem($systemPrompt));

        foreach ($history as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $role    = (string) ($entry['role'] ?? '');
            $content = (string) ($entry['content'] ?? '');
            if ('' === trim($content)) {
                continue;
            }
            if ('user' === $role) {
                $bag->add(Message::ofUser($content));
            } elseif ('assistant' === $role) {
                $bag->add(Message::ofAssistant($content));
            }
        }

        $bag->add(Message::ofUser($userInput));
        return $bag;
    }

    private function errorResponse(int $status, string $message): Response
    {
        return new Response(
            json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE),
            $status,
            [
                'Content-Type'  => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store, private, max-age=0',
            ],
        );
    }

    private function requireBackendUser(): BackendUser
    {
        if (null === $this->tokenChecker->getBackendUsername()) {
            throw new AccessDeniedException('No backend session.');
        }
        $user = BackendUser::getInstance();
        if (!$user instanceof BackendUser) {
            throw new AccessDeniedException('Invalid backend user.');
        }
        return $user;
    }

    /**
     * H-6: never echo the raw exception message back to the client. Stack traces,
     * vendor paths, API keys in URL/header dumps and DB DSNs all surface via
     * `getMessage()` from underlying HTTP/Anthropic libraries. Original goes to
     * the configured logger; client gets a scrubbed, masked, truncated string.
     */
    private function safeMessage(\Throwable $e): string
    {
        $this->logger->error('contao_ai_backend agent error', ['exception' => $e]);

        $message = str_replace($this->projectDir, '…', $e->getMessage());
        $message = self::maskSecrets($message);
        if (\strlen($message) > 200) {
            $message = mb_strcut($message, 0, 200) . '…';
        }
        return $message ?: 'Interner Fehler — siehe Logfile';
    }

    /**
     * Mask common API-key shapes so a stray exception that quotes the key does
     * not surface it to the SSE consumer. Patterns cover Anthropic (`sk-ant-…`)
     * and OpenAI (`sk-…`) plus generic Bearer-Tokens. Belt-and-suspenders — keys
     * should never be in `getMessage()` to begin with, but we have no contract
     * with the upstream libraries.
     */
    private static function maskSecrets(string $message): string
    {
        return (string) preg_replace(
            [
                '/sk-ant-[A-Za-z0-9_-]{6,}/',
                '/\bsk-[A-Za-z0-9]{20,}/',
                '/Bearer\s+[A-Za-z0-9._\-]{20,}/i',
            ],
            ['sk-ant-***', 'sk-***', 'Bearer ***'],
            $message,
        );
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function loadHistory(Request $request, BackendUser $user): array
    {
        if (!$request->hasSession()) {
            return [];
        }
        $stored = $request->getSession()->get($this->sessionKey($user), []);
        return \is_array($stored) ? array_values(array_filter(
            $stored,
            static fn ($e): bool => \is_array($e) && isset($e['role'], $e['content'])
        )) : [];
    }

    private function appendHistory(Request $request, BackendUser $user, string $userInput, string $assistantContent): void
    {
        if (!$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        $key = $this->sessionKey($user);
        $history = (array) $session->get($key, []);

        $history[] = ['role' => 'user',      'content' => self::capEntry($userInput)];
        $history[] = ['role' => 'assistant', 'content' => self::capEntry($assistantContent)];

        if (\count($history) > self::MAX_HISTORY_MESSAGES) {
            $history = \array_slice($history, -self::MAX_HISTORY_MESSAGES);
        }
        // Drop oldest entries until total bytes fit. Two-step (count then bytes)
        // because a few short entries shouldn't get evicted by one fat one.
        while (\count($history) > 0 && self::historyByteSize($history) > self::MAX_HISTORY_TOTAL_BYTES) {
            array_shift($history);
        }
        $session->set($key, $history);
    }

    private static function capEntry(string $content): string
    {
        if (\strlen($content) <= self::MAX_HISTORY_ENTRY_BYTES) {
            return $content;
        }
        return mb_strcut($content, 0, self::MAX_HISTORY_ENTRY_BYTES) . '…[truncated]';
    }

    /**
     * @param list<array{role: string, content: string}> $history
     */
    private static function historyByteSize(array $history): int
    {
        $total = 0;
        foreach ($history as $entry) {
            $total += \strlen((string) ($entry['content'] ?? ''));
        }
        return $total;
    }

    private function sessionKey(BackendUser $user): string
    {
        return self::SESSION_HISTORY_KEY_PREFIX . (int) $user->id;
    }

    /**
     * M-8: same-origin verification belt-and-suspenders to CSRF. Modern browsers
     * send `Sec-Fetch-Site` automatically; older ones at least send `Origin` on
     * cross-origin POSTs. Reject anything that isn't `same-origin` / our host.
     * If both headers are absent (very old client / non-browser tooling), we let
     * CSRF handle it alone — same posture as Symfony's SameSite cookies.
     */
    private function assertSameOrigin(Request $request): void
    {
        $secFetchSite = $request->headers->get('Sec-Fetch-Site');
        if (null !== $secFetchSite && 'same-origin' !== $secFetchSite) {
            throw new AccessDeniedException('Cross-origin request blocked.');
        }

        $origin = $request->headers->get('Origin');
        if (null !== $origin && '' !== $origin) {
            $expected = $request->getSchemeAndHttpHost();
            if (0 !== strcasecmp($origin, $expected)) {
                throw new AccessDeniedException('Origin mismatch.');
            }
        }
    }

    /**
     * Two-tier sliding window: per-minute burst guard + per-day total cap.
     * Returns false when the request must be refused with 429.
     */
    private function checkRateLimit(Request $request, BackendUser $user): bool
    {
        if (!$request->hasSession()) {
            return true;
        }
        $session = $request->getSession();
        $key = self::SESSION_RATE_KEY_PREFIX . (int) $user->id;
        /** @var list<int> $hits */
        $hits = (array) $session->get($key, []);
        $now = time();

        // Drop everything outside the daily window — bounds the array under sustained use.
        $hits = array_values(array_filter($hits, static fn (int $t): bool => ($now - $t) <= self::RATE_DAY_SECONDS));

        $minuteHits = 0;
        foreach ($hits as $t) {
            if (($now - $t) <= self::RATE_WINDOW_SECONDS) {
                $minuteHits++;
            }
        }
        if ($minuteHits >= self::RATE_LIMIT_PER_MIN || \count($hits) >= self::RATE_LIMIT_PER_DAY) {
            $session->set($key, $hits);
            return false;
        }

        $hits[] = $now;
        $session->set($key, $hits);
        return true;
    }
}
