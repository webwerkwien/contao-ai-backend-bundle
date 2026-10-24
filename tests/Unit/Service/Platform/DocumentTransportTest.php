<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Service\Platform;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\Content\ContentInterface;
use Symfony\AI\Platform\Message\Content\Document;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Webwerkwien\ContaoAiBackendBundle\Service\AgentFactory;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformRegistry;

/**
 * Which installed bridge carries a PDF or an image to its provider, measured on the
 * request it would send (v1.0.0).
 *
 * The model catalogs say more than the contracts do: OpenRouter lists PDF input for
 * hundreds of models and the generic platform grants everything, yet neither can
 * normalize a document. AgentFactory::DOCUMENT_TRANSPORT holds what this test
 * measures; when symfony/ai adds or drops a normalizer, the two part ways here and
 * not in an editor's chat.
 */
class DocumentTransportTest extends TestCase
{
    private const PDF = '%PDF-1.4 transport probe';
    private const PNG = "\x89PNG\r\n\x1a\nprobe";

    /**
     * @return iterable<string, array{string, class-string, list<mixed>, string}>
     */
    public static function bridges(): iterable
    {
        yield 'anthropic' => ['anthropic', 'Symfony\AI\Platform\Bridge\Anthropic\Factory', ['sk-ant-test'], 'claude-sonnet-4-5'];
        yield 'openai' => ['openai', 'Symfony\AI\Platform\Bridge\OpenAi\Factory', ['sk-test'], 'gpt-4o'];
        yield 'openrouter' => ['openrouter', 'Symfony\AI\Platform\Bridge\OpenRouter\Factory', ['sk-or-test'], 'openai/gpt-4o'];
        yield 'generic' => ['generic', 'Symfony\AI\Platform\Bridge\Generic\Factory', ['https://example.test/v1', 'sk-test'], 'any-model'];
        yield 'openresponses' => ['openresponses', 'Symfony\AI\Platform\Bridge\OpenResponses\Factory', ['https://example.test', 'sk-test'], 'any-model'];
        yield 'ollama' => ['ollama', 'Symfony\AI\Platform\Bridge\Ollama\Factory', ['http://example.test:11434'], 'llava'];
    }

    /**
     * The list above is written by hand; which bridges an installation offers is not.
     * A bridge the registry finds and this test does not measure would get PDF or not
     * by whatever DOCUMENT_TRANSPORT happens to say.
     */
    public function testEveryBridgeTheRegistryOffersIsMeasured(): void
    {
        $measured = array_keys(iterator_to_array(self::bridges()));
        $offered  = array_keys((new PlatformRegistry())->all());

        self::assertNotEmpty($offered);
        self::assertSame([], array_values(array_diff($offered, $measured)), 'installed bridges without a transport measurement');
    }

    /**
     * @param class-string $factory
     * @param list<mixed>  $arguments
     */
    #[DataProvider('bridges')]
    public function testADocumentTravelsExactlyWhereTheListSaysSo(string $key, string $factory, array $arguments, string $model): void
    {
        $sent = self::send($factory, $arguments, $model, new Document(self::PDF, 'application/pdf'));

        self::assertSame(
            \in_array($key, AgentFactory::DOCUMENT_TRANSPORT, true),
            null !== $sent && str_contains($sent, base64_encode(self::PDF)),
            null === $sent ? "{$key}: the request was not built" : "{$key}: sent " . substr($sent, 0, 300),
        );
    }

    /**
     * @param class-string $factory
     * @param list<mixed>  $arguments
     */
    #[DataProvider('bridges')]
    public function testAnImageTravelsEverywhere(string $key, string $factory, array $arguments, string $model): void
    {
        $sent = self::send($factory, $arguments, $model, new Image(self::PNG, 'image/png'));

        self::assertNotNull($sent, "{$key}: the request was not built");
        self::assertStringContainsString(base64_encode(self::PNG), $sent, $key);
    }

    /**
     * The request body the bridge sends for one user message with this content, or
     * null when it cannot be built.
     *
     * @param class-string $factory
     * @param list<mixed>  $arguments
     */
    private static function send(string $factory, array $arguments, string $model, ContentInterface $content): ?string
    {
        if (!class_exists($factory)) {
            self::markTestSkipped("{$factory} is not installed");
        }

        $sent = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent .= (string) ($options['body'] ?? '');

            // Ollama asks what the model can do before it sends the message.
            if (str_ends_with($url, '/api/show')) {
                return new MockResponse('{"capabilities":["completion","vision"],"model_info":{}}');
            }

            return new MockResponse('{}', ['http_code' => 500]);
        });

        /** @var PlatformInterface $platform */
        $platform = $factory::createPlatform(...$arguments, httpClient: $client);

        try {
            $platform->invoke($model, new MessageBag(Message::ofUser('Lies das', $content)))->getResult();
        } catch (\Throwable) {
            // The answer does not matter, only what was sent.
        }

        return $sent;
    }
}
