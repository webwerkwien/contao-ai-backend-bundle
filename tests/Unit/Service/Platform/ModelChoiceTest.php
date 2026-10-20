<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Service\Platform;

use Contao\BackendUser;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\AnthropicBridge;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\ModelChoice;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\OpenAiBridge;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformRegistry;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformResolver;
use Webwerkwien\ContaoAiBackendBundle\Service\UserAiConfig;

/**
 * The models a user may pick in the chat (v0.20.0): the installation's list, else the
 * bridge's; the profile's model first. And what runs when nothing is picked.
 */
class ModelChoiceTest extends TestCase
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

            public function __isset($strKey)
            {
                return isset($this->fields[$strKey]);
            }
        };
    }

    /**
     * @param array<string, list<string>> $configured
     */
    private function choice(array $configured = []): ModelChoice
    {
        $registry = new PlatformRegistry([new AnthropicBridge(), new OpenAiBridge()]);

        return new ModelChoice(new UserAiConfig($registry), $registry, $configured);
    }

    public function testWithoutConfigurationTheBridgeOffersItsList(): void
    {
        self::assertSame(AnthropicBridge::MODELS, $this->choice()->choicesFor($this->user(['ai_platform' => 'anthropic'])));
        self::assertSame(OpenAiBridge::MODELS, $this->choice()->choicesFor($this->user(['ai_platform' => 'openai'])));
    }

    public function testTheInstallationsListWins(): void
    {
        $choice = $this->choice(['anthropic' => ['claude-haiku-5-5', 'claude-sonnet-5-5']]);

        self::assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5'], $choice->choicesFor($this->user(['ai_platform' => 'anthropic'])));
        self::assertSame('claude-haiku-5-5', $choice->defaultFor('anthropic'));
        self::assertSame(OpenAiBridge::MODELS[0], $choice->defaultFor('openai'), 'a platform not configured keeps the bridge list');
    }

    public function testTheProfilesModelComesFirstAndIsAllowed(): void
    {
        $user = $this->user(['ai_platform' => 'anthropic', 'ai_model' => 'claude-opus-5-5']);

        self::assertSame(['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'], $this->choice()->choicesFor($user));

        $own = $this->user(['ai_platform' => 'anthropic', 'ai_model' => 'claude-fable-5-1']);
        self::assertTrue($this->choice()->allows($own, 'claude-fable-5-1'), 'the profile\'s own model, even outside the list');
    }

    public function testANameFromTheBrowserOutsideTheChoicesIsRefused(): void
    {
        $user = $this->user(['ai_platform' => 'anthropic']);

        self::assertTrue($this->choice()->allows($user, 'claude-haiku-5-5'));
        self::assertFalse($this->choice()->allows($user, 'claude-3-opus-20240229'));
        self::assertFalse($this->choice()->allows($user, 'gpt-5.5'), 'another platform\'s model');
    }

    public function testADerivedProviderWithoutAModelOffersNothing(): void
    {
        self::assertSame([], $this->choice()->choicesFor($this->user(['ai_platform' => 'unknown'])));
        self::assertNull($this->choice()->defaultFor('unknown'));
    }

    /**
     * The resolver takes the installation's first model when the profile names none —
     * no longer the bridge's fixed claude-sonnet-4-5.
     */
    public function testTheResolverRunsTheInstallationsDefault(): void
    {
        $registry = new PlatformRegistry([new AnthropicBridge()]);
        $config = new UserAiConfig($registry);
        $resolver = new PlatformResolver($config, $registry, new ModelChoice($config, $registry, ['anthropic' => ['claude-haiku-5-5']]));

        $resolved = $resolver->resolve($this->user(['ai_platform' => 'anthropic', 'ai_api_key' => 'sk-test']));

        self::assertSame('claude-haiku-5-5', $resolved->model);
        self::assertSame('claude-opus-5-5', $resolver->resolve($this->user(['ai_platform' => 'anthropic', 'ai_api_key' => 'sk-test']), 'claude-opus-5-5')->model);
    }

    /**
     * symfony/ai v0.14.1 does not list the Claude 5.5 models. The platform is built the
     * way the chat builds it — through the registry, where the installed package's
     * factory does it and the bridge only adds its catalog. The first version of this
     * test built the catalog itself and passed while c5 answered "No provider found for
     * model claude-sonnet-5-5": the bridge's createPlatform() never runs there (rule 24).
     */
    public function testTheCurrentClaudeModelsReachThePlatformTheChatBuilds(): void
    {
        $registry = new PlatformRegistry([new AnthropicBridge()]);
        self::assertTrue($registry->get('anthropic')->isDerived(), 'the package factory builds it, not the bridge');

        $catalog = $registry->createPlatform('anthropic', 'sk-test')->getModelCatalog();

        foreach (AnthropicBridge::MODELS as $model) {
            self::assertSame($model, $catalog->getModel($model)->getName());
        }
        self::assertSame('claude-sonnet-4-6', $catalog->getModel('claude-sonnet-4-6')->getName(), 'the catalog\'s own models stay');
    }
}
