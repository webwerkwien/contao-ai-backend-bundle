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
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\InMemoryPlatform;
use Webwerkwien\ContaoAiBackendBundle\Controller\AiStreamController;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Service\AgentInvocation;

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

#[AsTool('probe_foreign', 'throws what Contao might')]
final class ProbeForeignTool
{
    public function __invoke(): string
    {
        throw new \RuntimeException('intern');
    }
}
