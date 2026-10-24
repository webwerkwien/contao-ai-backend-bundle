<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Controller;

use Contao\BackendUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Webwerkwien\ContaoAiBackendBundle\Attachment\AttachmentReader;
use Webwerkwien\ContaoAiBackendBundle\Controller\AiStreamController;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\AgentFactory;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\AnthropicBridge;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformDescriptor;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformResolver;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\ResolvedPlatform;
use Webwerkwien\ContaoAiBackendBundle\Service\SystemPromptProvider;
use Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Attachment\AttachmentReaderTest;
use Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Support\BuildsAttachmentPolicy;

/**
 * The PDF goes with its own message and never again: measured at the request
 * Anthropic receives, not by reading the source.
 */
class AttachmentReachesTheModelTest extends TestCase
{
    use BuildsAttachmentPolicy;

    public function testThePdfGoesOnceAndTheHistoryKeepsItsName(): void
    {
        $sent   = new \ArrayObject();
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($sent): MockResponse {
            $sent[] = json_decode((string) $options['body'], true);

            return new MockResponse((string) json_encode([
                'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
                'content' => [['type' => 'text', 'text' => 'gelesen']], 'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ]), ['response_headers' => ['content-type' => 'application/json']]);
        });
        $platform = Factory::createPlatform('test-key', ...['httpClient' => $client] + (new AnthropicBridge())->factoryArguments());
        $resolver = $this->createMock(PlatformResolver::class);
        $resolver->method('resolve')->willReturn(new ResolvedPlatform($platform, 'claude-sonnet-5-5', new PlatformDescriptor('anthropic', 'Anthropic', Factory::class, 'symfony/ai-anthropic-platform')));
        $factory    = new AgentFactory([], $resolver, $this->createMock(SystemPromptProvider::class), $this->createMock(ToolAccessChecker::class), $this->createMock(EventDispatcherInterface::class), new NullLogger());
        $invocation = $factory->createForUser($this->createMock(BackendUser::class));

        $reader   = new AttachmentReader($this->attachmentPolicy(['uploadTypes' => 'pdf']));
        $accepted = $reader->read([['name' => 'Angebot.pdf', 'data' => base64_encode(AttachmentReaderTest::PDF)]], $invocation->readableContent, $invocation->model);
        $turnText = $accepted->turnText('Fass das zusammen');

        // first turn: text and document
        AiStreamController::runAgent($invocation, AiStreamController::buildMessageBag('System', [], $turnText, $accepted->platformContents()), 'x');
        $first  = $sent[0]['messages'];
        $blocks = end($first)['content'];
        self::assertSame('text', $blocks[0]['type']);
        self::assertStringContainsString('Anhang: Angebot.pdf', $blocks[0]['text']);
        self::assertSame('document', $blocks[1]['type']);
        self::assertSame('application/pdf', $blocks[1]['source']['media_type']);
        self::assertSame(base64_encode(AttachmentReaderTest::PDF), $blocks[1]['source']['data']);

        // next turn: the history as the controller stores it, without the file
        $history = [['role' => 'user', 'content' => $turnText], ['role' => 'assistant', 'content' => 'gelesen']];
        AiStreamController::runAgent($invocation, AiStreamController::buildMessageBag('System', $history, 'Und jetzt?'), 'x');
        self::assertStringNotContainsString('"document"', (string) json_encode($sent[1]));
        self::assertStringContainsString('Anhang: Angebot.pdf', (string) json_encode($sent[1], \JSON_UNESCAPED_UNICODE));
    }
}
