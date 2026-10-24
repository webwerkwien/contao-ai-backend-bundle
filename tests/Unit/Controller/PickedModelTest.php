<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Controller;

use Contao\BackendUser;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Controller\AiStreamController;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\AnthropicBridge;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\ModelChoice;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\OpenAiBridge;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformRegistry;
use Webwerkwien\ContaoAiBackendBundle\Service\UserAiConfig;

/**
 * The model name the chat page sends (v0.20.0): one of the user's choices, or a 400. Until
 * a later version the check sat inline in the controller, and a mutation that
 * let every name through left the suite green.
 */
class PickedModelTest extends TestCase
{
    private function user(): BackendUser
    {
        return new class extends BackendUser {
            public function __construct()
            {
            }

            public function __get($strKey)
            {
                return ['ai_platform' => 'anthropic', 'ai_api_key' => 'sk-test'][$strKey] ?? null;
            }

            public function __isset($strKey)
            {
                return \in_array($strKey, ['ai_platform', 'ai_api_key'], true);
            }
        };
    }

    private function choice(): ModelChoice
    {
        $registry = new PlatformRegistry([new AnthropicBridge(), new OpenAiBridge()]);

        return new ModelChoice(new UserAiConfig($registry), $registry, ['anthropic' => ['claude-haiku-5-5', 'claude-sonnet-5-5']]);
    }

    public function testAChoiceIsTaken(): void
    {
        self::assertSame([true, 'claude-sonnet-5-5'], AiStreamController::pickedModel(' claude-sonnet-5-5 ', $this->choice(), $this->user()));
    }

    public function testANameOutsideTheChoicesIsRefused(): void
    {
        self::assertSame([false, null], AiStreamController::pickedModel('claude-opus-5-5', $this->choice(), $this->user()), 'a bridge model the installation did not list');
        self::assertSame([false, null], AiStreamController::pickedModel('gpt-5.5', $this->choice(), $this->user()), 'another platform\'s model');
    }

    public function testNothingPickedMeansTheProfileOrTheDefault(): void
    {
        self::assertSame([true, null], AiStreamController::pickedModel(null, $this->choice(), $this->user()));
        self::assertSame([true, null], AiStreamController::pickedModel('  ', $this->choice(), $this->user()));
    }

    public function testWithoutModelChoiceEveryNameIsRefused(): void
    {
        self::assertSame([false, null], AiStreamController::pickedModel('claude-haiku-5-5', null, $this->user()));
        self::assertSame([true, null], AiStreamController::pickedModel(null, null, $this->user()));
    }

    public function testSomethingOtherThanAStringIsRefused(): void
    {
        self::assertSame([false, null], AiStreamController::pickedModel(['claude-haiku-5-5'], $this->choice(), $this->user()));
    }
}
