<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Event\ToolCallFailed;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Webwerkwien\ContaoAiBackendBundle\EventListener\ToolCallLogger;

/**
 * These entries reach tl_log, and ContaoTableHandler formats with
 * LineFormatter('%message%') - the context array is dropped there. So whatever
 * an auditor needs has to be in the message itself.
 */
class ToolCallLoggerTest extends TestCase
{
    /** @var list<string> */
    private array $messages = [];

    private function logger(): LoggerInterface
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(
            function (string $message) : void {
                $this->messages[] = $message;
            }
        );

        return $logger;
    }

    private function metadata(string $name): Tool
    {
        return new Tool(new ExecutionReference('SomeTool'), $name, 'description');
    }

    public function testRequestedNamesTheToolInTheMessage(): void
    {
        $listener = new ToolCallLogger($this->logger());
        $listener->onRequested(new ToolCallRequested(
            new ToolCall('call_1', 'page_update', ['id' => 5]),
            $this->metadata('page_update'),
        ));

        $this->assertCount(1, $this->messages);
        $this->assertStringContainsString('page_update', $this->messages[0]);
    }

    public function testSucceededNamesTheToolInTheMessage(): void
    {
        $call     = new ToolCall('call_1', 'page_update');
        $listener = new ToolCallLogger($this->logger());
        $listener->onSucceeded(new ToolCallSucceeded(
            new \stdClass(),
            $this->metadata('page_update'),
            [],
            new ToolResult($call, 'ok'),
        ));

        $this->assertStringContainsString('page_update', $this->messages[0]);
    }

    public function testFailedNamesTheToolAndTheExceptionClass(): void
    {
        $listener = new ToolCallLogger($this->logger());
        $listener->onFailed(new ToolCallFailed(
            new \stdClass(),
            $this->metadata('record_clone'),
            [],
            new \RuntimeException('nope'),
        ));

        $this->assertStringContainsString('record_clone', $this->messages[0]);
        $this->assertStringContainsString('RuntimeException', $this->messages[0]);
    }

    /**
     * getToolNames() drives whether the assistant turn is stubbed in the
     * persisted history, so it has to stay unaffected by the message change.
     */
    public function testStillTracksToolNamesForTheRequest(): void
    {
        $listener = new ToolCallLogger($this->logger());
        $listener->startRequest();
        $listener->onRequested(new ToolCallRequested(
            new ToolCall('call_1', 'page_update'),
            $this->metadata('page_update'),
        ));

        $this->assertSame(['page_update'], $listener->getToolNames());
    }
}
