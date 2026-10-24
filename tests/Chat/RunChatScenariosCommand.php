<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Chat;

use Contao\BackendUser;
use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\CoreBundle\Session\Attribute\ArrayAttributeBag;
use Doctrine\DBAL\Connection;
use Symfony\AI\Agent\Toolbox\Event\ToolCallFailed;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Yaml\Yaml;
use Webwerkwien\ContaoAiBackendBundle\Attachment\AttachmentReader;
use Webwerkwien\ContaoAiBackendBundle\Attachment\ChatAttachments;
use Webwerkwien\ContaoAiBackendBundle\Controller\AiStreamController;
use Webwerkwien\ContaoAiBackendBundle\EventListener\ToolCallLogger;
use Webwerkwien\ContaoAiBackendBundle\Exception\AttachmentRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Service\AgentFactory;
use Webwerkwien\ContaoAiCoreBundle\Service\Writer\RecordWriterInterface;

/**
 * Runs tests/Chat/scenarios.yaml against a real installation: every scenario on a fresh
 * fixture, as the named user, through the agent of the chat page. Development only —
 * this class is not part of the package (tests/ is export-ignored) and is wired on the
 * test server alone.
 *
 * The user is signed in as the back end does it: a security token in the token storage
 * and in the session under `_security_contao_backend`, so ToolAccessChecker, Contao's
 * voters and the field rights all decide as on the chat page. Nothing is skipped; with
 * `--without-token` every write must be refused (the counter-check that the rights
 * really come from the token).
 *
 * What it does not cover: HTTP (firewall, CSRF, the session's rate limit) and how the
 * page shows an answer. Those stay with the browser checks.
 */
#[AsCommand('contao-ai:chat-scenarios', 'Run the chat scenarios on this installation (development only)')]
final class RunChatScenariosCommand extends Command
{
    /** @var array<int|string, array<string, mixed>> calls of the turn running, by call id */
    private array $calls = [];

    /** where the scenario file lies: a turn's attachments are relative to it */
    private string $scenarioDir = '';

    /**
     * @param UserProviderInterface<BackendUser> $users
     */
    public function __construct(
        private readonly AgentFactory $agentFactory,
        private readonly ToolCallLogger $toolCallLogger,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RequestStack $requestStack,
        private readonly ContaoFramework $framework,
        private readonly RecordWriterInterface $writer,
        private readonly Connection $db,
        #[Autowire(service: 'contao.security.backend_user_provider')]
        private readonly UserProviderInterface $users,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        /** @var iterable<VoterInterface> */
        #[AutowireIterator('security.voter')]
        private readonly iterable $voters,
        private readonly AttachmentReader $attachmentReader,
        private readonly ChatAttachments $chatAttachments,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to scenarios.yaml')
            ->addOption('ai-from', null, InputOption::VALUE_REQUIRED, 'Back-end user whose AI settings (platform, key, model) the test users borrow, in memory only')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated scenario ids')
            ->addOption('no-alt', null, InputOption::VALUE_NONE, 'Only the first wording of each scenario')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Write the full transcript as JSON here')
            ->addOption('keep', null, InputOption::VALUE_NONE, 'Leave fixture and users in place after the run')
            ->addOption('without-token', null, InputOption::VALUE_NONE, 'Counter-check: run without signing in')
            ->addOption('fixture-only', null, InputOption::VALUE_NONE, 'Build the fixture and the users, then stop')
            ->addOption('votes', null, InputOption::VALUE_REQUIRED, 'Diagnosis: role:ref:field — every voter\'s vote on updating that field of the fixture record as that role');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $spec = Yaml::parseFile((string) $input->getArgument('file'));
        $this->scenarioDir = \dirname((string) $input->getArgument('file'));
        $fixture = new ChatFixture($this->writer, $this->db, $this->framework, $this->projectDir . '/var/chat-runner-state.json');
        $expectations = new ChatExpectations($this->db, $fixture);

        $this->pushRequest(self::newSession());
        $this->framework->initialize();
        $this->requestStack->pop();

        $fixture->rebuild((array) $spec['fixture']);
        $usernames = $fixture->syncUsers((array) $spec['fixture']['users']);

        if ($votes = $input->getOption('votes')) {
            [$role, $ref, $field] = explode(':', (string) $votes, 3) + [null, null, 'read'];
            $this->printVotes($output, $this->users->loadUserByIdentifier($usernames[(string) $role]), $fixture, (string) $ref, (string) $field);

            return Command::SUCCESS;
        }

        if ($input->getOption('fixture-only')) {
            $output->writeln('Fixture built: ' . json_encode(array_keys((array) $spec['fixture']['pages']))  . ', users: ' . implode(', ', $usernames));

            return Command::SUCCESS;
        }

        $aiFrom = (string) $input->getOption('ai-from');
        $aiSource = '' !== $aiFrom ? $this->users->loadUserByIdentifier($aiFrom) : null;
        if (!$aiSource instanceof BackendUser) {
            $output->writeln('<error>--ai-from names no back-end user; the test users need AI settings to borrow.</error>');

            return Command::FAILURE;
        }

        $this->listen();

        $only = array_filter(array_map('trim', explode(',', (string) $input->getOption('only'))));
        $runs = [];
        foreach ((array) $spec['scenarios'] as $scenario) {
            if ([] !== $only && !\in_array($scenario['id'], $only, true)) {
                continue;
            }
            $wordings = [$scenario['turns']];
            if (!$input->getOption('no-alt')) {
                foreach ((array) ($scenario['alt'] ?? []) as $alt) {
                    // an alternative replaces the first turn; later turns stay
                    $wordings[] = [$alt, ...\array_slice((array) $scenario['turns'], 1)];
                }
            }
            foreach ($wordings as $n => $turns) {
                $runs[] = [$scenario, $turns, 0 === $n ? (string) $scenario['id'] : $scenario['id'] . '#' . $n];
            }
        }

        $report = [];
        $passed = 0;
        $skipped = 0;
        $available = null;
        foreach ($runs as [$scenario, $turns, $label]) {
            if ([] !== ($requires = (array) ($scenario['requires'] ?? []))) {
                $available ??= $this->toolsOfferedTo(self::borrowAi($this->users->loadUserByIdentifier($usernames['admin']), $aiSource));
                if ([] !== ($missing = array_diff($requires, $available))) {
                    $output->writeln(\sprintf('SKIP %-40s not on this installation: %s', $label, implode(', ', $missing)));
                    ++$skipped;
                    continue;
                }
            }

            $fixture->rebuild((array) $spec['fixture']);
            $fixture->syncUsers((array) $spec['fixture']['users']);

            $user = self::borrowAi($this->users->loadUserByIdentifier($usernames[(string) $scenario['user']]), $aiSource);

            $expect = (array) $scenario['expect'];
            $snapshot = $expectations->snapshot($expect);
            $started = microtime(true);
            $filled = array_map(fn (string|array $turn): string|array => \is_array($turn)
                ? ['text' => $this->fill((string) ($turn['text'] ?? ''), $fixture)] + $turn
                : $this->fill($turn, $fixture), $turns);
            $recorded = $this->runTurns($user, $filled, !$input->getOption('without-token'));
            $failures = $expectations->check($expect, $snapshot, $recorded);

            $ok = [] === $failures;
            $passed += $ok ? 1 : 0;
            $output->writeln(\sprintf('%s %-40s %5.1fs %s', $ok ? '<info>PASS</info>' : '<error>FAIL</error>', $label, microtime(true) - $started, $ok ? '' : implode(' | ', $failures)));
            $report[] = ['id' => $label, 'user' => $scenario['user'], 'passed' => $ok, 'failures' => $failures, 'turns' => $recorded];
        }

        $output->writeln(\sprintf('%d of %d passed', $passed, \count($runs) - $skipped) . ($skipped > 0 ? ", {$skipped} skipped" : ''));

        if ($out = $input->getOption('out')) {
            file_put_contents((string) $out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        }

        if (!$input->getOption('keep')) {
            foreach ($fixture->teardown() as $left) {
                $output->writeln("<comment>left alone (not recognised as fixture): {$left}</comment>");
            }
            $fixture->removeUsers();
        }

        return $passed === \count($runs) - $skipped ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * The test users hold no key: they borrow platform, key and model in memory,
     * never saved.
     */
    private static function borrowAi(BackendUser $user, BackendUser $source): BackendUser
    {
        foreach (['ai_platform', 'ai_api_key', 'ai_base_url', 'ai_model'] as $field) {
            $user->__set($field, $source->__get($field));
        }

        return $user;
    }

    /**
     * Every tool name an admin is offered here — an optional extension's tools are
     * missing where the extension is.
     *
     * @return list<string>
     */
    private function toolsOfferedTo(BackendUser $admin): array
    {
        $session = self::newSession();
        $session->start();
        $token = new UsernamePasswordToken($admin, 'contao_backend', $admin->getRoles());
        $session->set('_security_contao_backend', serialize($token));
        $this->pushRequest($session);
        $this->tokenStorage->setToken($token);

        try {
            return $this->agentFactory->createForUser($admin)->registeredToolNames;
        } finally {
            $this->tokenStorage->setToken(null);
            $this->requestStack->pop();
        }
    }

    /**
     * One request per turn, as the chat page has; one session per scenario, which holds
     * the sign-in and the staged confirmations between turns.
     *
     * A turn is its text, or {text, attach: [path, …]} with files relative to the
     * scenario file (v1.0.0). They pass the same reader as on the chat page.
     *
     * @param list<string|array{text?: string, attach?: list<string>}> $turns
     *
     * @return list<array<string, mixed>>
     */
    private function runTurns(BackendUser $user, array $turns, bool $signIn): array
    {
        $session = self::newSession();
        $session->start();
        $token = new UsernamePasswordToken($user, 'contao_backend', $user->getRoles());
        if ($signIn) {
            $session->set('_security_contao_backend', serialize($token));
        }

        $history = [];
        $recorded = [];
        foreach ($turns as $turn) {
            [$text, $files] = \is_array($turn) ? [(string) ($turn['text'] ?? ''), (array) ($turn['attach'] ?? [])] : [$turn, []];
            $this->pushRequest($session);
            $this->tokenStorage->setToken($signIn ? $token : null);
            $this->calls = [];
            $answer = '';
            $error = null;

            try {
                $invocation = $this->agentFactory->createForUser($user);
                $raw = array_map(fn (string $path): array => ['name' => basename($path), 'data' => base64_encode((string) file_get_contents($this->scenarioDir . '/' . $path))], $files);
                $accepted = $this->attachmentReader->read($raw, $invocation->readableContent, $invocation->model);
                $messages = AiStreamController::buildMessageBag($invocation->systemPrompt, $history, $accepted->turnText($text), $accepted->platformContents());
                $this->toolCallLogger->startRequest();
                $this->chatAttachments->set($accepted->files);
                $answer = AiStreamController::runAgent($invocation, $messages, 'Dieses Werkzeug steht dir nicht zur Verfügung.');
                $history[] = ['role' => 'user', 'content' => $accepted->turnText($text)];
                $history[] = ['role' => 'assistant', 'content' => AiStreamController::buildHistoryAssistantContent($answer, $this->toolCallLogger->getToolNames())];
            } catch (AttachmentRefusedException $e) {
                $error = ['kind' => 'attachment_refused', 'message' => $e->labelKey . ' ' . json_encode($e->parameters, JSON_UNESCAPED_UNICODE)];
            } catch (ToolAccessDeniedException $e) {
                $error = ['kind' => 'access_denied', 'message' => $e->getMessage()];
            } catch (ToolRefusedException $e) {
                $error = ['kind' => 'tool_refused', 'message' => $e->getMessage()];
            } catch (ToolExecutionException $e) {
                $error = ['kind' => 'tool_failed', 'message' => $e->getMessage()];
            } catch (MaxOutputTokensException $e) {
                // As the chat page names it: the model's limit, not a crash.
                $error = ['kind' => 'output_cut', 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                $error = ['kind' => 'agent_failed', 'message' => $e::class . ': ' . $e->getMessage()];
            } finally {
                $this->chatAttachments->reset();
                $this->tokenStorage->setToken(null);
                $this->requestStack->pop();
            }

            // Scrubbed as the chat page does before anything reaches --out: a provider's
            // error can carry a key fragment or a path.
            $key = (string) $user->__get('ai_api_key');
            if (null !== $error) {
                $error['message'] = AiStreamController::scrubMessage((string) $error['message'], $this->projectDir, $key, 2000);
            }
            $calls = $this->recordedCalls();
            foreach ($calls as $id => $call) {
                if (\is_string($call['result'] ?? null)) {
                    $calls[$id]['result'] = AiStreamController::scrubMessage($call['result'], $this->projectDir, $key, 2000);
                }
            }

            $recorded[] = ['prompt' => $text, 'text' => $answer, 'error' => $error, 'calls' => $calls, 'tool_calls' => \count(array_filter(array_keys($calls), static fn ($id): bool => !str_starts_with((string) $id, 'failed-')))];
        }

        return $recorded;
    }

    /**
     * Which voter says what — when a scenario is refused and the refusal names no reason.
     */
    private function printVotes(OutputInterface $output, BackendUser $user, ChatFixture $fixture, string $ref, string $field): void
    {
        $table = $fixture->table($ref);
        $row = $this->db->fetchAssociative("SELECT * FROM {$table} WHERE id = ?", [$fixture->id($ref)]) ?: [];
        $session = self::newSession();
        $session->start();
        $token = new UsernamePasswordToken($user, 'contao_backend', $user->getRoles());
        $session->set('_security_contao_backend', serialize($token));
        $this->pushRequest($session);
        $this->tokenStorage->setToken($token);
        $this->framework->getAdapter(Controller::class)->loadDataContainer($table);

        $action = 'read' === $field ? new ReadAction($table, $row) : new UpdateAction($table, $row, [$field => 'x']);
        $attribute = ContaoCorePermissions::DC_PREFIX . $table;
        foreach ($this->voters as $voter) {
            if ($voter instanceof CacheableVoterInterface && !$voter->supportsAttribute($attribute)) {
                continue;
            }
            try {
                $vote = $voter->vote($token, $action, [$attribute]);
            } catch (\Throwable $e) {
                $output->writeln(\sprintf('%-8s %s (%s)', 'error', $voter::class, $e->getMessage()));
                continue;
            }
            if (VoterInterface::ACCESS_ABSTAIN !== $vote) {
                $output->writeln(\sprintf('%-8s %s', VoterInterface::ACCESS_GRANTED === $vote ? 'granted' : 'DENIED', $voter::class));
            }
        }

        $this->tokenStorage->setToken(null);
        $this->requestStack->pop();
    }

    /**
     * A session as Contao's SessionFactory builds it: with the `contao_backend` and
     * `contao_frontend` attribute bags. Creating a page or an element goes through
     * callbacks that read them (the first run failed 13 times on "The SessionBagInterface
     * contao_backend is not registered").
     */
    private static function newSession(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $backend = new ArrayAttributeBag('_contao_be_attributes');
        $backend->setName('contao_backend');
        $session->registerBag($backend);
        $frontend = new ArrayAttributeBag('_contao_fe_attributes');
        $frontend->setName('contao_frontend');
        $session->registerBag($frontend);

        return $session;
    }

    /**
     * What the listeners recorded during the turn (they write $this->calls).
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function recordedCalls(): array
    {
        return $this->calls;
    }

    private function pushRequest(Session $session): void
    {
        $request = Request::create('/contao/ai-chat/stream', 'POST');
        $request->attributes->set('_scope', 'backend');
        $request->setSession($session);
        $this->requestStack->push($request);
    }

    /**
     * The calls with their arguments — what the page never shows, and what took a
     * temporary log line on the server to see on 2026-10-19.
     */
    private function listen(): void
    {
        $this->dispatcher->addListener(ToolCallRequested::class, function (ToolCallRequested $event): void {
            $call = $event->getToolCall();
            $this->calls[$call->getId()] = ['name' => $call->getName(), 'arguments' => $call->getArguments(), 'ok' => false, 'result' => null];
        });
        $this->dispatcher->addListener(ToolCallSucceeded::class, function (ToolCallSucceeded $event): void {
            $id = $event->getResult()->getToolCall()->getId();
            $result = $event->getResult()->getResult();
            $this->calls[$id]['ok'] = true;
            $this->calls[$id]['result'] = mb_substr(\is_string($result) ? $result : (string) json_encode($result, JSON_UNESCAPED_UNICODE), 0, 1500);
        });
        $this->dispatcher->addListener(ToolCallFailed::class, function (ToolCallFailed $event): void {
            $name = $event->getDefinition()->getName();
            $this->calls['failed-' . \count($this->calls)] = ['name' => $name, 'arguments' => $event->getArguments(), 'ok' => false, 'result' => $event->getException()::class . ': ' . mb_substr($event->getException()->getMessage(), 0, 500)];
        });
    }

    private function fill(string $turn, ChatFixture $fixture): string
    {
        return (string) preg_replace_callback('/\{([a-z0-9_]+)\}/', static fn (array $m): string => $fixture->has($m[1]) ? (string) $fixture->id($m[1]) : $m[0], $turn);
    }
}
