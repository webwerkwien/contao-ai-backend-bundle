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
     * Rule 25: the key comes from the descriptor the run uses, not from the test.
     */
    public function testTheFactoryPassesTheKeyOfThePlatformItRuns(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 4) . '/src/Service/AgentFactory.php');

        self::assertStringContainsString('self::readableContent($platform, $resolved->modelForRun(), $resolved->descriptor->key)', $source);
    }
}
