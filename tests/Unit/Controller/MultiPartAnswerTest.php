<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Webwerkwien\ContaoAiBackendBundle\Controller\AiStreamController;
use Webwerkwien\ContaoAiBackendBundle\Service\AgentInvocation;

/**
 * An answer in several parts — thinking next to text, as claude-haiku-5-5 sends it — is
 * the text parts (v0.20.0). Cast to string the MultiPartResult read "Array" in the chat
 * (c5, 2026-10-20). Through the real agent and runAgent(), as the chat page runs it.
 */
class MultiPartAnswerTest extends TestCase
{
    public function testTheTextPartsAreTheAnswer(): void
    {
        $platform = MockPlatformFactory::createPlatform(static fn () => new MultiPartResult([
            new ThinkingResult('The user wants a short answer.'),
            new TextResult('OK, '),
            new TextResult('erledigt.'),
        ]));
        $invocation = new AgentInvocation(new Agent($platform, 'probe-model', toolbox: new Toolbox([])), 'system', 'probe-model', [], []);

        self::assertSame('OK, erledigt.', AiStreamController::runAgent($invocation, new MessageBag(Message::ofUser('x')), 'nicht freigegeben'));
    }

    public function testAPlainTextAnswerStaysAsItIs(): void
    {
        $platform = MockPlatformFactory::createPlatform(static fn () => 'fertig');
        $invocation = new AgentInvocation(new Agent($platform, 'probe-model', toolbox: new Toolbox([])), 'system', 'probe-model', [], []);

        self::assertSame('fertig', AiStreamController::runAgent($invocation, new MessageBag(Message::ofUser('x')), 'nicht freigegeben'));
    }
}
