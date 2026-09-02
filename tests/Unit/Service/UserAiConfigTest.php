<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Service;

use Contao\BackendUser;
use PHPUnit\Framework\TestCase;
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
        $dto = $this->config()->getForUser($this->user([
            'username'    => 'michael',
            'ai_platform' => 'anthropic',
            'ai_api_key'  => 'sk-ant-secret',
        ]));

        self::assertSame('anthropic', $dto->platform);
        self::assertTrue($dto->hasApiKey());
    }

    public function testAPlatformThatIsNotInstalledFallsBack(): void
    {
        // A stray DB value — a manual edit, a partial migration, or a bridge
        // package that was uninstalled after a user had selected it. The check
        // asks the registry, so removing a package stops it validating on the
        // same day instead of failing later inside the factory with a stack
        // trace nobody can read.
        $dto = $this->config()->getForUser($this->user([
            'username'    => 'michael',
            'ai_platform' => 'a-provider-nobody-installed',
            'ai_api_key'  => 'sk-ant-secret',
        ]));

        self::assertSame('anthropic', $dto->platform);
    }

    public function testAnEmptyPlatformFallsBack(): void
    {
        $dto = $this->config()->getForUser($this->user([
            'username'   => 'michael',
            'ai_api_key' => 'sk-ant-secret',
        ]));

        self::assertSame('anthropic', $dto->platform);
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
