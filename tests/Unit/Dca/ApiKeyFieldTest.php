<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Dca;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Dca\ApiKeyField;

/**
 * The user form shows a mask instead of the stored API key (v0.17.0): until then every
 * user allowed to edit users received the others' keys in clear in the form.
 */
class ApiKeyFieldTest extends TestCase
{
    private mixed $dcaBefore;

    protected function setUp(): void
    {
        $this->dcaBefore = $GLOBALS['TL_DCA'] ?? false;
    }

    protected function tearDown(): void
    {
        if (false === $this->dcaBefore) {
            unset($GLOBALS['TL_DCA']);
        } else {
            $GLOBALS['TL_DCA'] = $this->dcaBefore;
        }
    }

    private function field(string|false $stored = 'sk-ant-secret'): ApiKeyField
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql, array $params): string|false => str_contains($sql, 'ai_api_key') && [3] === $params ? $stored : false,
        );

        return new ApiKeyField($connection);
    }

    /**
     * The record the save callback sees carries the values being saved when the write
     * comes through contao-ai-core-bundle (`--set ai_api_key=********`): reading the key
     * from it stored the mask (review 2026-10-14). Here it carries the mask on purpose.
     */
    private function dc(): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key) => 'id' === $key ? 3 : null);
        $dc->method('getCurrentRecord')->willReturn(['id' => 3, 'ai_api_key' => ApiKeyField::MASK]);

        return $dc;
    }

    public function testAStoredKeyReachesTheFormOnlyAsTheMask(): void
    {
        $this->assertSame(ApiKeyField::MASK, $this->field()->onLoad('sk-ant-secret'));
    }

    public function testNoKeyShowsAnEmptyField(): void
    {
        $this->assertSame('', $this->field()->onLoad(''));
        $this->assertSame('', $this->field()->onLoad(null));
    }

    public function testTheUnchangedMaskKeepsTheStoredKeyFromTheDatabase(): void
    {
        $this->assertSame('sk-ant-secret', $this->field()->onSave(ApiKeyField::MASK, $this->dc()));
    }

    /**
     * The mask is never stored, not even where nothing was stored before.
     */
    public function testTheMaskWithoutAStoredKeyStoresNothing(): void
    {
        $this->assertSame('', $this->field('')->onSave(ApiKeyField::MASK, $this->dc()));
        $this->assertSame('', $this->field(false)->onSave(ApiKeyField::MASK, $this->dc()));
    }

    public function testANewValueReplacesTheKey(): void
    {
        $this->assertSame('sk-ant-new', $this->field()->onSave(' sk-ant-new ', $this->dc()));
    }

    /**
     * Clearing the field still disables the AI module for the user, as the label says.
     */
    public function testAnEmptyFieldRemovesTheKey(): void
    {
        $this->assertSame('', $this->field()->onSave('', $this->dc()));
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

    /**
     * The DCA file itself, loaded: hideInput (password input, show view, version diff),
     * doNotShow (undo view; also out of "edit multiple"), doNotCopy (duplicated user).
     */
    public function testTheDcaKeepsTheKeyOutOfEveryView(): void
    {
        foreach (['default', 'admin', 'group', 'extend', 'custom', 'login'] as $palette) {
            $GLOBALS['TL_DCA']['tl_user']['palettes'][$palette] = '{name_legend},username;{account_legend},disable';
        }

        include __DIR__ . '/../../../contao/dca/tl_user.php';

        $eval = $GLOBALS['TL_DCA']['tl_user']['fields']['ai_api_key']['eval'] ?? [];

        $this->assertTrue($eval['hideInput'] ?? false);
        $this->assertTrue($eval['doNotShow'] ?? false);
        $this->assertTrue($eval['doNotCopy'] ?? false);
        $this->assertStringContainsString('ai_api_key', $GLOBALS['TL_DCA']['tl_user']['palettes']['login'], 'the user keeps editing their own key');
    }
}
