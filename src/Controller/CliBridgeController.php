<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Controller;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
use Webwerkwien\ContaoAiBackendBundle\Tool\RecordCloneTool;
use Webwerkwien\ContaoAiCoreBundle\Service\CredentialMasker;
use Webwerkwien\ContaoAiBackendBundle\Service\UserAiConfig;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Tool\RecordRewriteTool;

/**
 * Phase 10.1: HTTP-Endpoint, den der Python-CLI-Agent aufruft, um Macro-Tools
 * (record_clone, record_rewrite) ohne Browser-Wechsel auszuführen.
 *
 * Auth ist Bearer-Token (tl_user.ai_cli_token, password_hash gespeichert).
 * Token-Format <userId>.<random> erspart das Iterieren über alle User.
 *
 * Vor dem Tool-Aufruf wird ein Symfony-Security-PostAuthenticationToken in den
 * TokenStorage geschoben, damit BackendUser::getInstance() / TokenChecker und
 * die nachgeschalteten Phase-9.5-Voter exakt wie im Backend-Chat funktionieren.
 * Nach dem Aufruf wird der Token wieder entfernt — der Request bleibt stateless.
 */
class CliBridgeController extends AbstractController
{
    private const FIREWALL = 'contao_backend';
    private const MAX_BODY_BYTES = 32768;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly Connection $connection,
        private readonly RecordCloneTool $cloneTool,
        private readonly RecordRewriteTool $rewriteTool,
        private readonly string $projectDir,
        private readonly UserAiConfig $userConfig,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    // Route lives OUTSIDE /contao/* on purpose: the contao_backend firewall
    // matches the contao path and preview.php and would 302-redirect any
    // unauthenticated request to /contao/login before our Bearer-auth runs.
    // /_ai_cli/macro falls through to the frontend firewall (anonymous);
    // the controller does its own auth via Authorization header.
    #[Route(
        '/_ai_cli/macro',
        name: 'contao_ai_backend_cli_bridge',
        methods: ['POST'],
        defaults: ['_token_check' => false],
    )]
    public function __invoke(Request $request): Response
    {
        $this->framework->initialize();

        $tokenHeader = $this->extractBearer($request);
        if (null === $tokenHeader) {
            return $this->error(401, 'Missing or malformed Authorization header.');
        }

        $user = $this->resolveUser($tokenHeader);
        if (null === $user) {
            return $this->error(401, 'Invalid CLI bridge token.');
        }

        $body = $request->getContent();
        if (\strlen($body) > self::MAX_BODY_BYTES) {
            return $this->error(413, 'Request body too large.');
        }
        try {
            $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->error(400, 'Invalid JSON body.');
        }
        if (!\is_array($payload)) {
            return $this->error(400, 'JSON body must be an object.');
        }

        $tool = (string) ($payload['tool'] ?? '');
        if (!\in_array($tool, ['record_clone', 'record_rewrite'], true)) {
            return $this->error(422, \sprintf('Unsupported tool "%s".', $tool));
        }

        // Push the resolved user into the security context for the duration of
        // the tool invocation. The Phase-9.5 AuthorizationChecker (Voter pipeline)
        // reads the token from TokenStorage and works outside the contao_backend
        // firewall — but Contao's TokenChecker::hasBackendUser() refuses unless
        // the firewall context matches, so AbstractCoreCommandTool's user lookup
        // gets an explicit acting-user override. Both are cleared in `finally`.
        $previousToken = $this->tokenStorage->getToken();
        $authToken = new PostAuthenticationToken($user, self::FIREWALL, $user->getRoles());
        $this->tokenStorage->setToken($authToken);
        $this->cloneTool->setActingUserOverride($user);
        $this->rewriteTool->setActingUserOverride($user);

        // Both secrets this request can surface: the bearer token it arrived
        // with, and the LLM key the rewrite tool spends downstream.
        $secrets = [$tokenHeader, $this->userConfig->getForUser($user)->getApiKey()];

        try {
            $resultJson = $this->dispatchTool($tool, $payload);
            return new JsonResponse(
                ['status' => 'ok', 'tool' => $tool, 'result' => $this->unwrapSentinel($resultJson)],
                200,
                $this->securityHeaders(),
                false,
            );
        } catch (ToolAccessDeniedException $e) {
            return $this->error(403, $e->getMessage());
        } catch (ToolRefusedException $e) {
            // 🔴 2026-09-02: this used to be 500. A record that does not exist is
            // not a server error, and contao-ai-cli v0.15.0 now reads a 500 from
            // the probe call as "your token works, the bridge is broken" — so a
            // mistyped id would have accused a healthy bridge.
            //
            // 422 joins the \InvalidArgumentException branch below on purpose:
            // both mean "the request was understood and cannot be carried out",
            // which is exactly what `bridge configure --test` expects to see.
            return $this->error(422, $e->getMessage());
        } catch (ToolExecutionException $e) {
            return $this->error(500, $this->safeMessage($e, ...$secrets));
        } catch (\InvalidArgumentException $e) {
            return $this->error(422, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->error(500, $this->safeMessage($e, ...$secrets));
        } finally {
            $this->cloneTool->setActingUserOverride(null);
            $this->rewriteTool->setActingUserOverride(null);
            $this->tokenStorage->setToken($previousToken);
        }
    }

    private function extractBearer(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');
        if ('' === $header) {
            return null;
        }
        if (!preg_match('/^Bearer\s+([A-Za-z0-9._\-]{16,256})$/', $header, $m)) {
            return null;
        }
        return $m[1];
    }

    /**
     * Token format: "<userId>.<random>". Splits, loads BackendUser by id, runs
     * password_verify against tl_user.ai_cli_token. Constant-time-equivalent
     * thanks to password_verify's built-in timing-safe compare.
     */
    private function resolveUser(string $token): ?BackendUser
    {
        $dot = strpos($token, '.');
        if (false === $dot || 0 === $dot) {
            return null;
        }
        $userIdRaw = substr($token, 0, $dot);
        $secret = substr($token, $dot + 1);
        if ('' === $secret || !ctype_digit($userIdRaw)) {
            return null;
        }
        $userId = (int) $userIdRaw;
        if ($userId <= 0) {
            return null;
        }

        $hash = $this->connection->fetchOne(
            'SELECT ai_cli_token FROM tl_user WHERE id = :id AND disable = 0 AND (start = 0 OR start <= UNIX_TIMESTAMP()) AND (stop = 0 OR stop >= UNIX_TIMESTAMP())',
            ['id' => $userId],
        );
        if (!\is_string($hash) || '' === $hash) {
            return null;
        }
        if (!password_verify($secret, $hash)) {
            return null;
        }

        /** @var class-string<BackendUser> $class */
        $class = BackendUser::class;
        $user = $class::loadUserByIdentifier($this->fetchUsername($userId));
        return $user instanceof BackendUser ? $user : null;
    }

    private function fetchUsername(int $userId): string
    {
        $username = $this->connection->fetchOne(
            'SELECT username FROM tl_user WHERE id = :id',
            ['id' => $userId],
        );
        return \is_string($username) ? $username : '';
    }

    /** @param array<string, mixed> $payload */
    private function dispatchTool(string $tool, array $payload): string
    {
        if ('record_clone' === $tool) {
            $sourceTable = (string) ($payload['table'] ?? $payload['sourceTable'] ?? '');
            $sourceId    = (int)    ($payload['sourceId'] ?? 0);
            $modifications = $payload['modifications'] ?? [];
            $recursive   = (bool)   ($payload['recursive'] ?? false);
            if ('' === $sourceTable || $sourceId <= 0) {
                throw new \InvalidArgumentException('record_clone requires "table" and "sourceId".');
            }
            return $this->cloneTool->cloneRecord($sourceTable, $sourceId, $modifications, $recursive);
        }

        // record_rewrite
        $table        = (string) ($payload['table'] ?? '');
        $id           = (int)    ($payload['id'] ?? 0);
        $instructions = (string) ($payload['instructions'] ?? '');
        $recursive    = (bool)   ($payload['recursive'] ?? false);
        if ('' === $table || $id <= 0 || '' === $instructions) {
            throw new \InvalidArgumentException('record_rewrite requires "table", "id" and "instructions".');
        }
        return $this->rewriteTool->rewriteRecord($table, $id, $instructions, $recursive);
    }

    /**
     * Tool methods return their JSON wrapped in <tool_output_data tool="..."> sentinels
     * for the LLM. The bridge consumer (Python-CLI) parses raw JSON, so we strip the
     * wrapper and return the inner payload.
     */
    private function unwrapSentinel(string $wrapped): mixed
    {
        if (preg_match('/^<tool_output_data tool="[^"]+">\s*(.*?)\s*<\/tool_output_data>$/s', $wrapped, $m)) {
            try {
                return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $wrapped;
            }
        }
        return $wrapped;
    }

    private function error(int $status, string $message): JsonResponse
    {
        return new JsonResponse(
            ['status' => 'error', 'message' => $message],
            $status,
            $this->securityHeaders(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function securityHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, private, max-age=0',
            'X-Robots-Tag'  => 'noindex, nofollow',
            'Vary'          => 'Authorization',
        ];
    }

    private function safeMessage(\Throwable $e, #[\SensitiveParameter] string ...$secrets): string
    {
        // This was the second, hand-copied pattern list. Two places with the
        // same three regexes and no test — so both aged together and neither
        // was noticed. Now one service, one set of tests.
        $this->logger->error('contao_ai_backend cli bridge error', CredentialMasker::context($e, ...$secrets));
        $message = str_replace($this->projectDir, '…', $e->getMessage());
        $message = CredentialMasker::mask($message, ...$secrets);
        if (\strlen($message) > 200) {
            $message = mb_strcut($message, 0, 200) . '…';
        }
        return $message ?: 'Interner Fehler — siehe Logfile';
    }
}
