<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Service;

use Contao\BackendUser;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Exception\AiConfigException;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformRegistry;
use Webwerkwien\ContaoAiBackendBundle\Service\UserAiConfig;

/**
 * What the chat reads out of a backend user's profile.
 *
 * 🎯 This file exists because of a discovery on 2026-09-02: after rewriting
 * `UserAiConfig` and `AgentFactory` for the derived platform registry, the full
 * suite stayed green — **because neither class had a single test.** Eighty-five
 * passing tests said nothing at all about the two classes that had changed most.
 *
 * That is the same shape as the `page_publish` bug found the same morning: not a
 * wrong test, an absent one, and nothing about a green run distinguishes the two.
 */
class UserAiConfigTest extends TestCase
{
    /**
     * A hand-written double rather than a PHPUnit mock: `__get` is a magic
     * method and PHPUnit 10 refuses to configure it. Contao's BackendUser has a
     * protected singleton constructor, which a subclass may widen — the parent
     * constructor is deliberately not called, since nothing here needs the
     * framework, only the field values.
     *
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

            /**
             * Overriding `__get` alone is not enough, and the way it fails is
             * instructive: `$user->ai_api_key ?? ''` calls **`__isset()` first**
             * and only reaches `__get()` when that says yes. Contao's inherited
             * `__isset` looks in `arrData`, which this double never fills — so
             * every read came back as the empty string and the config silently
             * fell back to its default. The null-coalescing operator looks like
             * a null check; on a magic property it is an existence check.
             */
            public function __isset($strKey)
            {
                return isset($this->fields[$strKey]);
            }
        };
    }

    private function config(): UserAiConfig
    {
        return new UserAiConfig(new PlatformRegistry([]));
    }

    public function testAnInstalledPlatformIsKept(): void
    {
        // Deliberately NOT `anthropic`: that is the value the default would also
        // produce, so an assertion on it would pass even if nothing was read.
        // (Fable review, M-4 — the same "green through the fallback" shape this
        // file's header already warns about.)
        $dto = $this->config()->getForUser($this->user([
            'username'    => 'michael',
            'ai_platform' => 'openai',
            'ai_api_key'  => 'sk-openai-secret',
        ]));

        self::assertSame('openai', $dto->platform);
        self::assertTrue($dto->hasApiKey());
    }

    /**
     * 🔴 C-1. This test asserted the exact opposite until 2026-09-02: that an
     * uninstalled provider falls back to `anthropic`. It was green, and it
     * certified a credential leak.
     *
     * Reproduced before the fix: with `ai_platform = mistral` and a Mistral key,
     * `composer remove symfony/ai-mistral-platform` was enough to make the next
     * chat send that key to api.anthropic.com — one log warning, no error for
     * the user.
     *
     * 🎯 A stored key belongs to exactly the provider the user chose. Change the
     * provider and the key is wrong — so the provider is never substituted.
     */
    public function testAPlatformThatIsNotInstalledIsNotSwapped(): void
    {
        $dto = $this->config()->getForUser($this->user([
            'username'    => 'michael',
            'ai_platform' => 'mistral',
            'ai_api_key'  => 'MISTRAL-KEY',
        ]));

        self::assertSame('mistral', $dto->platform, 'the stored provider was silently replaced');
    }

    public function testTheStoredKeyNeverReachesADifferentProvider(): void
    {
        // The invariant itself, checked end to end: the config hands the unknown
        // provider through, and the registry refuses it by name instead of
        // quietly building a platform for someone else.
        $registry = new PlatformRegistry([]);
        $dto      = (new UserAiConfig($registry))->getForUser($this->user([
            'username'    => 'michael',
            'ai_platform' => 'mistral',
            'ai_api_key'  => 'MISTRAL-KEY',
        ]));

        self::assertNotSame('anthropic', $dto->platform);
        self::assertNotSame('openai', $dto->platform);

        $this->expectException(AiConfigException::class);
        $this->expectExceptionMessageMatches('/mistral/');
        $registry->get($dto->platform);
    }

    public function testAnEmptyPlatformWithNoKeyMayTakeTheDefault(): void
    {
        // Nothing configured at all — there is no key that could be sent to the
        // wrong place, so a default costs nothing.
        $dto = $this->config()->getForUser($this->user([
            'username' => 'michael',
        ]));

        self::assertSame('anthropic', $dto->platform);
        self::assertFalse($dto->hasApiKey());
    }

    public function testAKeyWithoutAProviderIsNotGuessed(): void
    {
        // A key is present but no provider was ever chosen. Picking one would be
        // the same mistake as C-1, one step earlier.
        $dto = $this->config()->getForUser($this->user([
            'username'   => 'michael',
            'ai_api_key' => 'SOME-KEY',
        ]));

        self::assertSame('', $dto->platform, 'a provider was guessed for an unassigned key');
    }

    public function testABaseUrlIsCarriedThroughAndTrimmed(): void
    {
        $dto = $this->config()->getForUser($this->user([
            'username'     => 'michael',
            'ai_platform'  => 'anthropic',
            'ai_api_key'   => 'sk-ant-secret',
            'ai_base_url'  => '  http://localhost:11434  ',
        ]));

        self::assertTrue($dto->hasBaseUrl());
        self::assertSame('http://localhost:11434', $dto->baseUrl);
    }

    public function testAnEmptyBaseUrlBecomesNullRatherThanAnEmptyString(): void
    {
        // The difference matters at the call site: null means "use the bridge's
        // own default", an empty string would be passed on as a base URL of "".
        $dto = $this->config()->getForUser($this->user([
            'username'    => 'michael',
            'ai_platform' => 'anthropic',
            'ai_api_key'  => 'sk-ant-secret',
            'ai_base_url' => '   ',
        ]));

        self::assertNull($dto->baseUrl);
        self::assertFalse($dto->hasBaseUrl());
    }

    public function testTheModelIsOptionalAndNullWhenBlank(): void
    {
        $dto = $this->config()->getForUser($this->user([
            'username'    => 'michael',
            'ai_platform' => 'anthropic',
            'ai_api_key'  => 'sk-ant-secret',
            'ai_model'    => '',
        ]));

        self::assertNull($dto->model);
    }

    public function testTheDebugDumpNeverShowsTheWholeKey(): void
    {
        $dto = $this->config()->getForUser($this->user([
            'username'    => 'michael',
            'ai_platform' => 'anthropic',
            'ai_api_key'  => 'sk-ant-api03-VERYSECRETVALUE',
        ]));

        $dump = print_r($dto, true);

        self::assertStringNotContainsString('VERYSECRETVALUE', $dump);
        self::assertStringContainsString('***', $dump);
    }
}
