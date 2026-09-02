<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Service\Platform;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\PlatformInterface;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformBridgeInterface;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformRegistry;

/**
 * The provider list is read from the installed bridges, not maintained.
 *
 * Until 2026-09-02 the DCA carried `'options' => ['anthropic', 'openai']` while
 * symfony/ai shipped 37 bridges: the two-provider limit was ours. These tests
 * pin the derivation against the packages actually installed in this repo's
 * `vendor/`, so they also fail if a future symfony/ai reshapes the factory
 * signature — which is the point. A hand-kept list would have gone on being
 * green while quietly describing something that no longer exists.
 */
class PlatformRegistryTest extends TestCase
{
    private function registry(PlatformBridgeInterface ...$bridges): PlatformRegistry
    {
        return new PlatformRegistry($bridges);
    }

    public function testItFindsTheInstalledBridgesAtAll(): void
    {
        // A scan that silently matches nothing passes as quietly as one that
        // matches everything. The first draft of the discovery code looked for
        // `PlatformFactory` — the name symfony/ai used before 0.13 — and found
        // exactly zero without failing. Only a counter revealed it.
        $all = $this->registry()->all();

        self::assertGreaterThanOrEqual(2, \count($all), 'no platform bridges discovered at all');
        self::assertArrayHasKey('anthropic', $all);
        self::assertArrayHasKey('openai', $all);
    }

    public function testTheBasePackageIsNotOfferedAsAProvider(): void
    {
        // symfony/ai-platform matches the naming pattern (starts with
        // `symfony/ai-`, ends with `-platform`) but is the base package. It has
        // nothing to ask a user for and must not appear in the select.
        foreach ($this->registry()->all() as $key => $descriptor) {
            self::assertNotSame('symfony/ai-platform', $descriptor->package, "base package offered as '$key'");
        }
    }

    public function testTheKeyComesFromTheBridgeNotFromUs(): void
    {
        // `name`'s default in the factory signature is the bridge's own
        // canonical key — and it matches what tl_user.ai_platform already
        // stores, which is why deriving the list needs no data migration.
        $all = $this->registry()->all();

        self::assertSame('anthropic', $all['anthropic']->key);
        self::assertSame('openai', $all['openai']->key);
    }

    public function testAnApiKeyProviderIsMarkedAsRequiringOne(): void
    {
        $anthropic = $this->registry()->get('anthropic');

        self::assertTrue($anthropic->acceptsApiKey());
        self::assertTrue($anthropic->apiKeyRequired);
        self::assertFalse($anthropic->isSelfHosted());
    }

    public function testNameIsNeverMistakenForACredential(): void
    {
        // `name` is a string parameter sitting right next to `apiKey` and
        // `baseUrl`. Reading it as either would send the platform key where a
        // credential belongs.
        foreach ($this->registry()->all() as $key => $descriptor) {
            self::assertNotSame('name', $descriptor->apiKeyParam, "'$key' reads name as the API key");
            self::assertNotSame('name', $descriptor->baseUrlParam, "'$key' reads name as the base URL");
        }
    }

    public function testAHostOnlyProviderIsRecognisedAsSuch(): void
    {
        // openresponses is installed here and is the shape that the old
        // interface could not express: base URL required, API key optional.
        $all = $this->registry()->all();

        if (!isset($all['openresponses'])) {
            self::markTestSkipped('symfony/ai-open-responses-platform is not installed');
        }

        $descriptor = $all['openresponses'];

        self::assertTrue($descriptor->acceptsBaseUrl());
        self::assertTrue($descriptor->baseUrlRequired);
        self::assertFalse($descriptor->apiKeyRequired);
        self::assertTrue($descriptor->isSelfHosted());
    }

    public function testAnInstalledPackageIsNotShadowedByABridgeClass(): void
    {
        // 🔴 The regression this test exists for. The first draft let any
        // PlatformBridgeInterface take over `createPlatform()` for its key. That
        // interface takes nothing but an API key, so a base URL stored by the
        // user would have been accepted by the form and then silently dropped.
        //
        // A field that takes input and changes nothing is worse than no field.
        $bridge = new class() implements PlatformBridgeInterface {
            public function getName(): string
            {
                return 'anthropic';
            }

            public function getDefaultModel(): string
            {
                return 'curated-model';
            }

            public function createPlatform(#[\SensitiveParameter] string $apiKey): PlatformInterface
            {
                throw new \LogicException('the installed package must build the platform, not this bridge');
            }
        };

        $descriptor = $this->registry($bridge)->get('anthropic');

        self::assertTrue($descriptor->isDerived(), 'a bridge class shadowed an installed package');
        self::assertSame('curated-model', $descriptor->defaultModel, 'the bridge should still supply the default model');
    }

    public function testAnUnknownPlatformNamesWhatIsAvailable(): void
    {
        $this->expectExceptionMessageMatches('/anthropic/');

        $this->registry()->get('does-not-exist');
    }

    public function testEveryPlatformWeRequireActuallyShowsUp(): void
    {
        // Michael's rule, 2026-09-02: "was wir über das Dropdown anbieten muss
        // auch mitgezogen werden." One direction is free — the list *is* the
        // installed set, so it cannot offer something absent.
        //
        // This is the other direction: every platform package the bundle
        // declares as a hard dependency must actually reach the dropdown. If a
        // future rename or a dropped `require` line breaks that, a fresh
        // install would quietly offer fewer providers than the README promises,
        // and nothing would fail.
        $manifest = json_decode(
            (string) file_get_contents(\dirname(__DIR__, 4).'/composer.json'),
            true,
        );

        $required = array_filter(
            array_keys($manifest['require'] ?? []),
            static fn (string $p) => str_starts_with($p, 'symfony/ai-')
                && str_ends_with($p, '-platform')
                && 'symfony/ai-platform' !== $p,
        );

        self::assertNotEmpty($required, 'no platform package is required at all');

        $shipped = [];
        foreach ($this->registry()->all() as $descriptor) {
            $shipped[] = $descriptor->package;
        }

        foreach ($required as $package) {
            self::assertContains(
                $package,
                $shipped,
                "$package is required but never reaches the provider list",
            );
        }
    }

    public function testEveryEntryCarriesALabel(): void
    {
        foreach ($this->registry()->all() as $key => $descriptor) {
            self::assertNotSame('', $descriptor->label, "'$key' has no label");
        }
    }
}
