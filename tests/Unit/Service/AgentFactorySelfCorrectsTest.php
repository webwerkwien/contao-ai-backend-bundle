<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Service;

use Contao\BackendUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\InMemoryPlatform;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Webwerkwien\ContaoAiBackendBundle\Controller\AiStreamController;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\AgentFactory;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformDescriptor;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformResolver;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\ResolvedPlatform;
use Webwerkwien\ContaoAiBackendBundle\Service\SystemPromptProvider;

/**
 * The agent the chat runs is the one that hands an invented tool name back to the
 * model (v0.12.0) — measured through AgentFactory itself, not by reading its source:
 * without SelfCorrectingToolbox this run ends in ToolNotFoundException.
 */
class AgentFactorySelfCorrectsTest extends TestCase
{
    public function testAnInventedToolNameDoesNotEndTheTurn(): void
    {
        $calls    = 0;
        $platform = new InMemoryPlatform(static function () use (&$calls) {
            return 0 === $calls++ ? new ToolCallResult([new ToolCall('c1', 'page_invented')]) : 'fertig';
        });

        $resolver = $this->createMock(PlatformResolver::class);
        $resolver->method('resolve')->willReturn(new ResolvedPlatform($platform, 'probe-model', new PlatformDescriptor('probe', 'Probe', null, 'probe/probe')));
        $access = $this->createMock(ToolAccessChecker::class);
        $access->method('listAllowedTools')->willReturn([]);

        $factory    = new AgentFactory([], $resolver, $this->createMock(SystemPromptProvider::class), $access, $this->createMock(EventDispatcherInterface::class), new NullLogger());
        $invocation = $factory->createForUser($this->createMock(BackendUser::class));

        self::assertSame('fertig', AiStreamController::runAgent($invocation, new MessageBag(Message::ofUser('x')), 'nicht freigegeben'));
    }
}
