<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Service\Platform;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\PlatformInterface;
use Webwerkwien\ContaoAiBackendBundle\Attachment\AttachmentContent;
use Webwerkwien\ContaoAiBackendBundle\Service\AgentFactory;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\AnthropicBridge;

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
            AgentFactory::readableContent($platform, 'claude-sonnet-5-5?max_tokens=8192'),
        );
    }

    public function testAModelTheCatalogDoesNotKnowReadsNothingButText(): void
    {
        $platform = Factory::createPlatform('test-key', ...(new AnthropicBridge())->factoryArguments());

        self::assertSame([], AgentFactory::readableContent($platform, 'claude-gibt-es-nicht'));
    }

    public function testAProviderWithoutCatalogIsTakenAtItsWord(): void
    {
        // OpenRouter and the generic platform list no models: the fallback grants all.
        $platform = $this->createMock(PlatformInterface::class);
        $platform->method('getModelCatalog')->willReturn(new FallbackModelCatalog());

        self::assertSame([AttachmentContent::DOCUMENT, AttachmentContent::IMAGE], AgentFactory::readableContent($platform, 'irgendwas'));
    }
}
