<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Security\FieldPermission;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\StructuredFields;

/**
 * A list from the model goes to the core as JSON (v1.0.0). The core decodes JSON only
 * for the fields its StructuredFields::storesArray() names; for any other field the
 * text `["a","b"]` would be stored as it is. So every field FieldPermission::storesList()
 * accepts has to be one the core decodes.
 *
 * Checked against Contao's own DCA files and the core's own list of input types, not
 * against definitions written here.
 */
class ListFieldsMatchCoreTest extends TestCase
{
    private const DCA_DIR = __DIR__ . '/../../../vendor/contao/core-bundle/contao/dca';

    protected function setUp(): void
    {
        if (!class_exists(StructuredFields::class)) {
            $this->markTestSkipped('contao-ai-core-bundle without StructuredFields (before v0.16.0)');
        }
    }

    /**
     * @return array<string, array<string, mixed>> "table.field" => definition
     */
    private static function contaoFields(): array
    {
        $before = $GLOBALS['TL_DCA'] ?? null;
        $fields = [];

        // Outside the framework some files read globals that are not set ($TL_LANGUAGE,
        // $TL_FFL); the warnings say nothing about the field definitions.
        set_error_handler(static fn (int $level): bool => E_WARNING === $level);

        // Contao includes a DCA file from Controller::loadDataContainer(), and some of
        // them call `$this->loadDataContainer()` for a table they build on. Every file is
        // read here anyway, so those calls are taken and ignored.
        $loader = new class {
            /**
             * @param list<mixed> $arguments
             */
            public function __call(string $name, array $arguments): void
            {
            }

            public function load(string $file): void
            {
                include $file;
            }
        };

        try {
            foreach (glob(self::DCA_DIR . '/tl_*.php') ?: [] as $file) {
                $table = basename($file, '.php');
                unset($GLOBALS['TL_DCA'][$table]);

                // a few files need the container (tl_files); the tables that matter here do not
                try {
                    $loader->load($file);
                } catch (\Throwable) {
                    continue;
                }

                foreach ($GLOBALS['TL_DCA'][$table]['fields'] ?? [] as $name => $definition) {
                    if (\is_array($definition)) {
                        $fields[$table . '.' . $name] = $definition;
                    }
                }
            }
        } finally {
            restore_error_handler();

            if (null === $before) {
                unset($GLOBALS['TL_DCA']);
            } else {
                $GLOBALS['TL_DCA'] = $before;
            }
        }

        return $fields;
    }

    public function testEveryListFieldInContaoIsOneTheCoreDecodes(): void
    {
        $fields = self::contaoFields();
        $this->assertGreaterThan(300, \count($fields), 'Contao\'s DCA files were not read');
        $this->assertArrayHasKey('tl_content.size', $fields);
        $this->assertArrayHasKey('tl_image_size.formats', $fields);

        $lists = array_keys(array_filter($fields, static fn (array $definition): bool => FieldPermission::storesList($definition)));
        $undecoded = array_values(array_filter($lists, static fn (string $field): bool => !StructuredFields::storesArray($fields[$field])));

        $this->assertSame([], $undecoded, 'fields that would get a JSON list the core stores as text');

        // the fields the chat writes as a list, and the file fields it never does
        $this->assertContains('tl_content.size', $lists);
        $this->assertContains('tl_image_size.formats', $lists);
        $this->assertNotContains('tl_content.singleSRC', $lists);
        $this->assertNotContains('tl_content.multiSRC', $lists);
    }

    public function testEveryInputTypeOfTheCoreAgrees(): void
    {
        foreach (StructuredFields::INPUT_TYPES as $type) {
            foreach ([['inputType' => $type], ['inputType' => $type, 'eval' => ['multiple' => true]], ['inputType' => $type, 'eval' => ['multiple' => true, 'csv' => ',']]] as $definition) {
                if (FieldPermission::storesList($definition)) {
                    $this->assertTrue(StructuredFields::storesArray($definition), json_encode($definition, JSON_THROW_ON_ERROR));
                }
            }
        }
    }

    /**
     * A multiple choice with `eval.csv` stores "a,b", which the core does not decode.
     */
    public function testACommaSeparatedMultipleChoiceTakesNoList(): void
    {
        $this->assertTrue(FieldPermission::storesList(['inputType' => 'checkbox', 'eval' => ['multiple' => true]]));
        $this->assertFalse(FieldPermission::storesList(['inputType' => 'checkbox', 'eval' => ['multiple' => true, 'csv' => ',']]));
        $this->assertFalse(StructuredFields::storesArray(['inputType' => 'checkbox', 'eval' => ['multiple' => true, 'csv' => ',']]));
    }
}
