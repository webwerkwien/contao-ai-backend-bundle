<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Dca;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Dca\ApiKeyField;

/**
 * The user form shows a mask instead of the stored API key (v0.17.0): until then every
 * user allowed to edit users received the others' keys in clear in the form.
 */
class ApiKeyFieldTest extends TestCase
{
    private function dc(string $stored): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn(['id' => 3, 'ai_api_key' => $stored]);

        return $dc;
    }

    public function testAStoredKeyReachesTheFormOnlyAsTheMask(): void
    {
        $this->assertSame(ApiKeyField::MASK, (new ApiKeyField())->onLoad('sk-ant-secret'));
        $this->assertStringNotContainsString('sk-', (new ApiKeyField())->onLoad('sk-ant-secret'));
    }

    public function testNoKeyShowsAnEmptyField(): void
    {
        $this->assertSame('', (new ApiKeyField())->onLoad(''));
        $this->assertSame('', (new ApiKeyField())->onLoad(null));
    }

    public function testTheUnchangedMaskKeepsTheStoredKey(): void
    {
        $this->assertSame('sk-ant-secret', (new ApiKeyField())->onSave(ApiKeyField::MASK, $this->dc('sk-ant-secret')));
    }

    public function testANewValueReplacesTheKey(): void
    {
        $this->assertSame('sk-ant-new', (new ApiKeyField())->onSave(' sk-ant-new ', $this->dc('sk-ant-secret')));
    }

    /**
     * Clearing the field still disables the AI module for the user, as the label says.
     */
    public function testAnEmptyFieldRemovesTheKey(): void
    {
        $this->assertSame('', (new ApiKeyField())->onSave('', $this->dc('sk-ant-secret')));
    }

    /**
     * Registered for exactly this field, both directions — without the load callback the
     * key would be sent again, without the save callback the mask would be stored.
     */
    public function testBothCallbacksAreRegisteredForTheField(): void
    {
        $targets = [];

        foreach ((new \ReflectionClass(ApiKeyField::class))->getMethods() as $method) {
            foreach ($method->getAttributes(AsCallback::class) as $attribute) {
                $targets[] = $attribute->getArguments()['table'] . ':' . $attribute->getArguments()['target'];
            }
        }

        sort($targets);
        $this->assertSame(['tl_user:fields.ai_api_key.load', 'tl_user:fields.ai_api_key.save'], $targets);
    }

    public function testTheFieldIsHiddenFromShowViewAndVersionDiff(): void
    {
        $dca = (string) file_get_contents(__DIR__ . '/../../../contao/dca/tl_user.php');

        $this->assertMatchesRegularExpression("/\\['ai_api_key'\\] = \\[.*?'hideInput' => true,.*?\\];/s", $dca);
    }
}
