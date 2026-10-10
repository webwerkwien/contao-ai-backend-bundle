<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException as ToolboxExecutionException;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\InMemoryPlatform;
use Webwerkwien\ContaoAiBackendBundle\Controller\AiStreamController;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Service\AgentInvocation;
use Webwerkwien\ContaoAiBackendBundle\Service\SelfCorrectingToolbox;

/**
 * What a failing run hands the chat's catch blocks — measured with a real Agent,
 * Toolbox and Runner of the installed symfony/ai, the model replaced by a
 * scripted InMemoryPlatform.
 *
 * 🔴 Measured on 2026-10-09 (symfony/ai 0.14.1, and per the pre-release review
 * 0.13 the same): the toolbox wraps every exception a tool throws in its own
 * `Toolbox\Exception\ToolExecutionException`, unless it implements
 * `ToolExecutionExceptionInterface` — ours do not. So none of the chat's catch
 * blocks for `access_denied`, `tool_refused` and `tool_failed` was ever reached:
 * "Seite 9 nicht gefunden" arrived as `agent_failed`, with a report inviting a
 * bug ticket. The tests that guarded the branches read the controller's source
 * and could not see it (rule 24). `runAgent()` unwraps ours.
 *
 * The same harness covers the refusal of symfony/ai 0.14 (#2602): a registered
 * tool the run left out becomes `ToolAccessDeniedException`, a name no tool has
 * stays `ToolNotFoundException` (review W2).
 */
class AgentRunErrorsTest extends TestCase
{
    private const ALL = ['probe_refused', 'probe_denied', 'probe_failed', 'probe_ok'];

    /**
     * @param list<string> $allowed
     */
    private function runProbe(string $toolName, array $allowed): string
    {
        $calls    = 0;
        $platform = new InMemoryPlatform(static function () use (&$calls, $toolName) {
            return 0 === $calls++ ? new ToolCallResult([new ToolCall('c1', $toolName)]) : 'fertig';
        });
        $toolbox    = new Toolbox([new ProbeRefusedTool(), new ProbeDeniedTool(), new ProbeFailedTool(), new ProbeOkTool(), new ProbeForeignTool()]);
        $invocation = new AgentInvocation(new Agent($platform, 'probe-model', toolbox: $toolbox), 'system', 'probe-model', $allowed, self::ALL);

        return AiStreamController::runAgent($invocation, new MessageBag(Message::ofUser('x')), 'nicht freigegeben');
    }

    public function testAnAllowedToolRuns(): void
    {
        self::assertSame('fertig', $this->runProbe('probe_ok', self::ALL));
    }

    /**
     * @return array<string, array{string, class-string<\Throwable>, string}>
     */
    public static function toolFailures(): array
    {
        return [
            'refusal'       => ['probe_refused', ToolRefusedException::class, 'Seite 9 nicht gefunden.'],
            'access denied' => ['probe_denied', ToolAccessDeniedException::class, 'Kein Zugriff auf Seite 9.'],
            'tool failed'   => ['probe_failed', ToolExecutionException::class, 'Datenbank weg.'],
        ];
    }

    /**
     * @dataProvider toolFailures
     *
     * @param class-string<\Throwable> $expected
     */
    public function testAToolsOwnExceptionReachesTheChatUnwrapped(string $tool, string $expected, string $message): void
    {
        try {
            $this->runProbe($tool, self::ALL);
            self::fail('expected ' . $expected);
        } catch (\Throwable $e) {
            self::assertSame($expected, $e::class);
            self::assertSame($message, $e->getMessage(), 'the message is ours, not "Execution of tool … failed with error: …"');
        }
    }

    /**
     * Review W2 of the unwrap fix: only our three exceptions are unwrapped. A
     * foreign one (a RuntimeException out of Contao) stays the toolbox's, falls to
     * \Throwable and reads as agent_failed with a report — a defect, as it should.
     * Unwrapping everything left the suite green until this test.
     */
    public function testAForeignExceptionStaysWrapped(): void
    {
        try {
            $this->runProbe('probe_foreign', [...self::ALL, 'probe_foreign']);
            self::fail('expected the toolbox exception');
        } catch (ToolboxExecutionException $e) {
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            self::assertSame('intern', $e->getPrevious()->getMessage());
        }
    }

    public function testARegisteredToolTheRunLeftOutIsAccessDenied(): void
    {
        try {
            $this->runProbe('probe_ok', ['probe_refused']);
            self::fail('expected a refusal');
        } catch (ToolAccessDeniedException $e) {
            self::assertSame('nicht freigegeben', $e->getMessage());
            self::assertInstanceOf(ToolNotFoundException::class, $e->getPrevious());
        }
    }

    public function testANameNoToolHasIsNotAPermissionMatter(): void
    {
        $this->expectException(ToolNotFoundException::class);

        $this->runProbe('probe_invented', self::ALL);
    }

    // --- v0.12.0: the model's own mistakes go back to the model ---------------------

    /**
     * Two model rounds through SelfCorrectingToolbox: the first calls $call, the
     * second sees the tool message and answers "fertig".
     *
     * @param list<string> $allowed
     * @return array{string, ?string} the run's answer and what the model was told
     */
    private function runCorrecting(ToolCall $call, array $allowed): array
    {
        $told     = null;
        $calls    = 0;
        $platform = new InMemoryPlatform(static function ($model, $input) use (&$calls, &$told, $call) {
            if (0 === $calls++) {
                return new ToolCallResult([$call]);
            }
            foreach ($input->getMessages() as $message) {
                if ($message instanceof ToolCallMessage) {
                    $told = $message->asText();
                }
            }

            return 'fertig';
        });
        $toolbox    = new SelfCorrectingToolbox(new Toolbox([new ProbeRefusedTool(), new ProbeOkTool(), new ProbeNeedsIdTool()]), $allowed);
        $invocation = new AgentInvocation(new Agent($platform, 'probe-model', toolbox: $toolbox), 'system', 'probe-model', $allowed, ['probe_refused', 'probe_ok', 'probe_needs_id']);

        return [AiStreamController::runAgent($invocation, new MessageBag(Message::ofUser('x')), 'nicht freigegeben'), $told];
    }

    public function testInvalidArgumentsGoBackToTheModelInsteadOfEndingTheTurn(): void
    {
        [$answer, $told] = $this->runCorrecting(new ToolCall('c1', 'probe_needs_id', []), ['probe_needs_id']);

        self::assertSame('fertig', $answer, 'the turn went on instead of agent_failed');
        self::assertStringContainsString('Parameter "id" is mandatory', (string) $told);
        self::assertStringContainsString('call it again', (string) $told);
    }

    public function testAnInventedNameGetsTheUsersToolsNotTheWholeToolbox(): void
    {
        [$answer, $told] = $this->runCorrecting(new ToolCall('c1', 'probe_invented'), ['probe_ok', 'probe_needs_id']);

        self::assertSame('fertig', $answer);
        self::assertStringContainsString('There is no tool "probe_invented"', (string) $told);
        self::assertStringContainsString('probe_ok, probe_needs_id', (string) $told);
        self::assertStringNotContainsString('probe_refused', (string) $told, 'registered, but not this user\'s');
    }

    public function testARefusalStillEndsTheTurn(): void
    {
        $this->expectException(ToolRefusedException::class);

        $this->runCorrecting(new ToolCall('c1', 'probe_refused'), ['probe_refused']);
    }

    public function testARegisteredToolTheRunLeftOutIsStillAccessDenied(): void
    {
        $this->expectException(ToolAccessDeniedException::class);

        $this->runCorrecting(new ToolCall('c1', 'probe_refused'), ['probe_ok']);
    }

    public function testARegisteredNameTheToolboxCannotFindIsADefectNotTheModelsMistake(): void
    {
        $tool  = new \Symfony\AI\Platform\Tool\Tool(new \Symfony\AI\Platform\Tool\ExecutionReference(ProbeOkTool::class), 'probe_ok', 'works');
        $inner = $this->createMock(\Symfony\AI\Agent\Toolbox\ToolboxInterface::class);
        $inner->method('getTools')->willReturn([$tool]);
        $inner->method('execute')->willThrowException(new ToolNotFoundException('Tool not found for reference: ProbeOkTool::__invoke.'));

        $this->expectException(ToolNotFoundException::class);

        (new SelfCorrectingToolbox($inner, ['probe_ok']))->execute(new ToolCall('c1', 'probe_ok'));
    }

    public function testTheControllerRunsTheAgentThroughIt(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/Controller/AiStreamController.php');

        self::assertSame(1, substr_count($source, '->agent->call('), 'the agent is called in one place');
        self::assertStringContainsString('self::runAgent($invocation, $messages, $this->label(\'tool_not_allowed\'))', $source);
    }
}

#[AsTool('probe_refused', 'refuses')]
final class ProbeRefusedTool
{
    public function __invoke(): string
    {
        throw new ToolRefusedException('Seite 9 nicht gefunden.');
    }
}

#[AsTool('probe_denied', 'denies')]
final class ProbeDeniedTool
{
    public function __invoke(): string
    {
        throw new ToolAccessDeniedException('Kein Zugriff auf Seite 9.');
    }
}

#[AsTool('probe_failed', 'fails')]
final class ProbeFailedTool
{
    public function __invoke(): string
    {
        throw new ToolExecutionException('Datenbank weg.');
    }
}

#[AsTool('probe_ok', 'works')]
final class ProbeOkTool
{
    public function __invoke(): string
    {
        return 'ok';
    }
}

#[AsTool('probe_needs_id', 'needs an id')]
final class ProbeNeedsIdTool
{
    public function __invoke(int $id): string
    {
        return 'Seite ' . $id;
    }
}

#[AsTool('probe_foreign', 'throws what Contao might')]
final class ProbeForeignTool
{
    public function __invoke(): string
    {
        throw new \RuntimeException('intern');
    }
}
