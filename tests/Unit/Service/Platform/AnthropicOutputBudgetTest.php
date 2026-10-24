<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Service\Platform;

use Contao\BackendUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Webwerkwien\ContaoAiBackendBundle\Controller\AiStreamController;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\AgentFactory;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\AnthropicBridge;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformDescriptor;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformResolver;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\ResolvedPlatform;
use Webwerkwien\ContaoAiBackendBundle\Service\SystemPromptProvider;

/**
 * symfony/ai gives Claude 1000 output tokens unless told otherwise. Until v0.23.0
 * the chat never told it: answers stopped there, and a tool call carrying a long
 * text was cut. Measured at the request the real Anthropic platform sends, built
 * through AgentFactory — not by reading the source.
 */
class AnthropicOutputBudgetTest extends TestCase
{
    /**
     * @return array{0: ResolvedPlatform, 1: \ArrayObject<int, array<string, mixed>>}
     */
    private function anthropic(string $model): array
    {
        $sent   = new \ArrayObject();
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($sent): MockResponse {
            $sent[] = json_decode((string) $options['body'], true);

            return new MockResponse((string) json_encode([
                'id'          => 'msg_1',
                'type'        => 'message',
                'role'        => 'assistant',
                'model'       => 'claude-sonnet-5-5',
                'content'     => [['type' => 'text', 'text' => 'fertig']],
                'stop_reason' => 'end_turn',
                'usage'       => ['input_tokens' => 1, 'output_tokens' => 1],
            ]), ['response_headers' => ['content-type' => 'application/json']]);
        });

        $platform = Factory::createPlatform('test-key', ...['httpClient' => $client] + (new AnthropicBridge())->factoryArguments());

        return [new ResolvedPlatform($platform, $model, new PlatformDescriptor('anthropic', 'Anthropic', Factory::class, 'symfony/ai-anthropic-platform')), $sent];
    }

    public function testTheChatAsksClaudeForTheBudget(): void
    {
        [$resolved, $sent] = $this->anthropic('claude-sonnet-5-5');

        $resolver = $this->createMock(PlatformResolver::class);
        $resolver->method('resolve')->willReturn($resolved);

        $factory    = new AgentFactory([], $resolver, $this->createMock(SystemPromptProvider::class), $this->createMock(ToolAccessChecker::class), $this->createMock(EventDispatcherInterface::class), new NullLogger());
        $invocation = $factory->createForUser($this->createMock(BackendUser::class));

        self::assertSame('fertig', AiStreamController::runAgent($invocation, new MessageBag(Message::ofUser('x')), 'nicht freigegeben'));
        self::assertCount(1, $sent);
        self::assertSame(ResolvedPlatform::ANTHROPIC_OUTPUT_TOKENS, $sent[0]['max_tokens'] ?? null);
        self::assertSame('claude-sonnet-5-5', $sent[0]['model'] ?? null, 'the option must not end up in the model name the API sees');
        self::assertSame('claude-sonnet-5-5', $invocation->model, 'the chat names the model without options');
    }

    public function testWithoutTheBudgetClaudeGetsTheLibraryDefault(): void
    {
        [$resolved, $sent] = $this->anthropic('claude-sonnet-5-5');

        $resolved->platform->invoke($resolved->model, new MessageBag(Message::ofUser('x')))->getResult();

        self::assertSame(1000, $sent[0]['max_tokens'] ?? null, 'symfony/ai no longer defaults to 1000 — check whether the budget is still needed');
    }

    public function testANameWithOptionsIsLeftAlone(): void
    {
        [$resolved] = $this->anthropic('claude-sonnet-5-5?max_tokens=2048');

        self::assertSame('claude-sonnet-5-5?max_tokens=2048', $resolved->modelForRun());
    }

    public function testOtherProvidersAreLeftAlone(): void
    {
        [$anthropic] = $this->anthropic('gpt-5.5');
        $openai      = new ResolvedPlatform($anthropic->platform, 'gpt-5.5', new PlatformDescriptor('openai', 'OpenAI', null, 'symfony/ai-open-ai-platform'));

        self::assertSame('gpt-5.5', $openai->modelForRun());
    }
}
