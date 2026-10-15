<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Controller;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Webwerkwien\ContaoAiBackendBundle\Controller\CliBridgeController;
use Webwerkwien\ContaoAiBackendBundle\Service\UserAiConfig;
use Webwerkwien\ContaoAiBackendBundle\Tool\RecordCloneTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\RecordRewriteTool;

/**
 * The bridge hands a clone to cloneFromBridge() — the path where the CLI has asked —
 * and passes withRecipients through (v0.19.0). The Fable review set the flag to false
 * in the controller and the whole suite stayed green.
 */
class CliBridgeCloneDispatchTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     */
    private function dispatch(RecordCloneTool $clone, array $payload): string
    {
        $controller = new CliBridgeController(
            $this->createMock(ContaoFramework::class),
            $this->createMock(TokenStorageInterface::class),
            $this->createMock(Connection::class),
            $clone,
            $this->createMock(RecordRewriteTool::class),
            '/tmp',
            $this->createMock(UserAiConfig::class),
        );

        return (fn (string $tool, array $payload): string => $this->dispatchTool($tool, $payload))
            ->call($controller, 'record_clone', $payload);
    }

    public function testRecipientsReachTheTool(): void
    {
        $clone = $this->createMock(RecordCloneTool::class);
        $clone->expects($this->never())->method('cloneRecord');
        $clone->expects($this->once())->method('cloneFromBridge')
            ->with('tl_newsletter_channel', 4, [], false, true)
            ->willReturn('ok');

        self::assertSame('ok', $this->dispatch($clone, ['table' => 'tl_newsletter_channel', 'sourceId' => 4, 'withRecipients' => true]));
    }

    public function testWithoutTheKeyThereAreNone(): void
    {
        $clone = $this->createMock(RecordCloneTool::class);
        $clone->expects($this->once())->method('cloneFromBridge')
            ->with('tl_newsletter_channel', 4, [], false, false)
            ->willReturn('ok');

        $this->dispatch($clone, ['table' => 'tl_newsletter_channel', 'sourceId' => 4]);
    }
}
