<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Service\Platform;

use Contao\BackendUser;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Exception\AiConfigException;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformRegistry;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformResolver;
use Webwerkwien\ContaoAiBackendBundle\Service\UserAiConfig;

/**
 * The one place that decides whether a profile can run — and what is missing.
 *
 * Three findings from the 2026-09-02 review live here as tests, because they
 * were one cause with three faces: the rule "ask the descriptor, not for a key"
 * moved into AgentFactory and three other call sites kept their own answer.
 *
 * - H-1 `record_rewrite` demanded a key unconditionally and knew two providers
 * - H-2 the chat UI hid itself whenever no key was stored
 * - H-3 a stored base URL was dropped for providers whose factory has no such
 *       parameter — accepted by the form, discarded on use
 */
class PlatformResolverTest extends TestCase
{
    /**
     * @param array<string, string|null> $fields
     */
    private function user(array $fields): BackendUser
    {
        return new class($fields) extends BackendUser {
            /** @param array<string, string|null> $fields */
            public function __construct(private readonly array $fields)
            {
            }

            public function __get($strKey)
            {
                return $this->fields[$strKey] ?? null;
            }

            /** `?? ` asks __isset first — see the vault note on this. */
            public function __isset($strKey)
            {
                return isset($this->fields[$strKey]);
            }
        };
    }

    private function resolver(): PlatformResolver
    {
        $registry = new PlatformRegistry([]);

        return new PlatformResolver(new UserAiConfig($registry), $registry);
    }

    public function testASelfHostedProviderNeedsNoKey(): void
    {
        // H-2. Ollama takes a host instead of a key. Before the fix this profile
        // produced "no API key" and the chat module rendered nothing at all.
        $blocker = $this->resolver()->missingRequirement($this->user([
            'username'    => 'michael',
            'ai_platform' => 'ollama',
            'ai_model'    => 'llama3.1',
        ]));

        self::assertNull($blocker, 'a self-hosted provider was refused for lacking a key');
    }

    public function testAKeyProviderWithoutAKeyNamesTheProvider(): void
    {
        $blocker = $this->resolver()->missingRequirement($this->user([
            'username'    => 'michael',
            'ai_platform' => 'anthropic',
        ]));

        self::assertNotNull($blocker);
        self::assertStringContainsString('Anthropic', $blocker, 'the message must say which provider');
    }

    public function testAnUninstalledProviderIsNamedRatherThanReplaced(): void
    {
        // C-1's other half: the profile is refused, not quietly redirected.
        $blocker = $this->resolver()->missingRequirement($this->user([
            'username'    => 'michael',
            'ai_platform' => 'mistral',
            'ai_api_key'  => 'MISTRAL-KEY',
        ]));

        self::assertNotNull($blocker);
        self::assertStringContainsString('mistral', $blocker);
    }

    public function testAProviderRequiringAnEndpointSaysSo(): void
    {
        $all = (new PlatformRegistry([]))->all();

        if (!isset($all['openresponses'])) {
            self::markTestSkipped('symfony/ai-open-responses-platform is not installed');
        }

        $blocker = $this->resolver()->missingRequirement($this->user([
            'username'    => 'michael',
            'ai_platform' => 'openresponses',
        ]));

        self::assertNotNull($blocker);
        self::assertStringContainsString('Endpunkt', $blocker);
    }

    public function testAnEndpointForAProviderThatHasNoneIsRefusedNotDropped(): void
    {
        // 🔴 H-3. OpenAI's factory has no `baseUrl` parameter. The old code
        // skipped the stored value without a word, so a user could point at a
        // gateway, see no error, and have the key go to api.openai.com anyway.
        $this->expectException(AiConfigException::class);
        $this->expectExceptionMessageMatches('/Endpunkt/');

        (new PlatformRegistry([]))->createPlatform('openai', 'sk-test-key-1234567890', 'https://gateway.example/v1');
    }

    public function testAKeyForAProviderThatTakesNoneIsRefusedNotDropped(): void
    {
        $all = (new PlatformRegistry([]))->all();

        // Bedrock-shaped: no apiKey parameter at all. Skipped where not installed
        // rather than asserted against a provider that happens to be absent.
        $keyless = null;
        foreach ($all as $key => $descriptor) {
            if (!$descriptor->acceptsApiKey()) {
                $keyless = $key;
                break;
            }
        }

        if (null === $keyless) {
            self::markTestSkipped('no installed provider refuses an API key');
        }

        $this->expectException(AiConfigException::class);
        (new PlatformRegistry([]))->createPlatform($keyless, 'sk-test-key-1234567890');
    }

    public function testAUsableProfileResolvesToAPlatformAndAModel(): void
    {
        // The positive path, so the tests above cannot all pass by refusing
        // everything — the failure mode this suite keeps producing.
        $resolved = $this->resolver()->resolve($this->user([
            'username'    => 'michael',
            'ai_platform' => 'anthropic',
            'ai_api_key'  => 'sk-ant-test-key-1234567890',
            'ai_model'    => 'claude-sonnet-4-5-20250929',
        ]));

        self::assertSame('claude-sonnet-4-5-20250929', $resolved->model);
        self::assertSame('anthropic', $resolved->descriptor->key);
    }
}
