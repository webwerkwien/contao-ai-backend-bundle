<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Service\Platform;

use Contao\BackendUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Webwerkwien\ContaoAiBackendBundle\Attachment\AttachmentContent;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\AgentFactory;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\AnthropicBridge;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformDescriptor;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformResolver;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\ResolvedPlatform;
use Webwerkwien\ContaoAiBackendBundle\Service\SystemPromptProvider;

/**
 * Read from symfony/ai's model catalog, the same one the run uses: a file the
 * model cannot read is refused before any request leaves.
 */
class ReadableContentTest extends TestCase
{
    public function testClaudeReadsPdfAndImages(): void
    {
        $platform = Factory::createPlatform('test-key', ...(new AnthropicBridge())->factoryArguments());

        self::assertSame(
            [AttachmentContent::DOCUMENT, AttachmentContent::IMAGE],
            AgentFactory::readableContent($platform, 'claude-sonnet-5-5?max_tokens=8192', 'anthropic'),
        );
    }

    public function testAModelTheCatalogDoesNotKnowReadsNothingButText(): void
    {
        $platform = Factory::createPlatform('test-key', ...(new AnthropicBridge())->factoryArguments());

        self::assertSame([], AgentFactory::readableContent($platform, 'claude-gibt-es-nicht', 'anthropic'));
    }

    /**
     * The generic platform lists no models and its fallback grants all; images it
     * carries, a PDF its contract cannot send (DocumentTransportTest measures both).
     */
    public function testAPlatformWithoutDocumentTransportReadsImagesOnly(): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $platform->method('getModelCatalog')->willReturn(new FallbackModelCatalog());

        self::assertSame([AttachmentContent::IMAGE], AgentFactory::readableContent($platform, 'irgendwas', 'generic'));
        self::assertSame([AttachmentContent::DOCUMENT, AttachmentContent::IMAGE], AgentFactory::readableContent($platform, 'irgendwas', 'openai'));
    }

    /**
     * The run's own invocation carries what its platform reads: the same platform and
     * model, once under a key that carries documents and once under one that does not.
     */
    public function testTheInvocationReadsWhatThePlatformItRunsCarries(): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $platform->method('getModelCatalog')->willReturn(new FallbackModelCatalog());

        $readable = function (string $key) use ($platform): array {
            $resolver = $this->createMock(PlatformResolver::class);
            $resolver->method('resolve')->willReturn(new ResolvedPlatform($platform, 'irgendwas', new PlatformDescriptor($key, $key, null, 'probe/probe')));
            $access = $this->createMock(ToolAccessChecker::class);
            $access->method('listAllowedTools')->willReturn([]);

            $factory = new AgentFactory([], $resolver, $this->createMock(SystemPromptProvider::class), $access, $this->createMock(EventDispatcherInterface::class), new NullLogger());

            return $factory->createForUser($this->createMock(BackendUser::class))->readableContent;
        };

        self::assertSame([AttachmentContent::DOCUMENT, AttachmentContent::IMAGE], $readable('openai'));
        self::assertSame([AttachmentContent::IMAGE], $readable('openrouter'));
    }
}
