<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * What FieldPermission and its tests assume about Contao, read from Contao's own files
 * (rule 25: the other tests write the DCA by hand, this one checks the hand against the
 * source). If Contao opens one of these fields (`'exclude' => false`) or changes how a
 * default is declared, this fails before the tests can pass on a picture of the past.
 */
class FieldPermissionContractTest extends TestCase
{
    private function definition(string $file, string $field): string
    {
        $path = __DIR__ . '/../../../vendor/contao/' . $file;

        if (!is_file($path)) {
            $this->markTestSkipped($file . ' is not installed.');
        }

        $source = (string) file_get_contents($path);
        $pattern = '/\n\t\t\'' . $field . '\' => array\s*\((.*?)\n\t\t\)/s';

        $this->assertSame(1, preg_match($pattern, $source, $match), "{$field} not found in {$file}");

        return $match[1];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fieldsTheChatWrites(): iterable
    {
        foreach (['title', 'alias', 'type', 'pageTitle', 'description', 'published', 'start', 'stop', 'hide'] as $field) {
            yield "tl_page.{$field}" => ['core-bundle/contao/dca/tl_page.php', $field];
        }
        foreach (['title', 'teaser', 'published', 'inColumn'] as $field) {
            yield "tl_article.{$field}" => ['core-bundle/contao/dca/tl_article.php', $field];
        }
        foreach (['type', 'headline', 'text', 'html'] as $field) {
            yield "tl_content.{$field}" => ['core-bundle/contao/dca/tl_content.php', $field];
        }
        foreach (['headline', 'date', 'time', 'published'] as $field) {
            yield "tl_news.{$field}" => ['news-bundle/contao/dca/tl_news.php', $field];
        }
    }

    /**
     * Excluded by Contao's rule, so an editor needs each of them granted. Not an
     * assumption the code relies on (it reads the live DCA) — but if one opens, the
     * examples in the tests and the CHANGELOG are wrong, and that should be noticed.
     *
     * @dataProvider fieldsTheChatWrites
     */
    public function testTheFieldsTheChatWritesAreNotOpenedByContao(string $file, string $field): void
    {
        $definition = $this->definition($file, $field);

        $this->assertStringContainsString("'inputType'", $definition, "{$field} has no input, so isFieldExcluded() would not count it");
        $this->assertDoesNotMatchRegularExpression("/'exclude'\s*=>\s*false/", $definition);
    }

    /**
     * The three ways a default is declared, as FieldPermission::isDefault() parses them.
     */
    public function testTheDefaultsAreDeclaredAsTheTestsAssume(): void
    {
        $this->assertStringContainsString(
            "'sql'                     => \"varchar(64) NOT NULL default 'regular'\"",
            $this->definition('core-bundle/contao/dca/tl_page.php', 'type'),
        );
        $this->assertMatchesRegularExpression(
            "/'sql'\s*=>\s*array\([^)]*'type'\s*=>\s*'string'[^)]*'default'\s*=>\s*'text'/",
            $this->definition('core-bundle/contao/dca/tl_content.php', 'type'),
        );
        $this->assertMatchesRegularExpression(
            "/'sql'\s*=>\s*array\('type'\s*=>\s*'boolean',\s*'default'\s*=>\s*false\)/",
            $this->definition('core-bundle/contao/dca/tl_page.php', 'published'),
        );
        $this->assertMatchesRegularExpression(
            "/'sql'\s*=>\s*array\('type'\s*=>\s*'boolean',\s*'default'\s*=>\s*true\)/",
            $this->definition('core-bundle/contao/dca/tl_page.php', 'useSSL'),
        );
    }

    /**
     * The rule itself: excluded unless the DCA says otherwise, and every field with an
     * input counts as excluded. DC_Table checks exactly this before saving a field.
     */
    public function testContaosRuleIsStillTheOneFieldPermissionFollows(): void
    {
        $path = __DIR__ . '/../../../vendor/contao/core-bundle/contao/classes/DataContainer.php';
        $source = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression(
            "/public static function isFieldExcluded\(string \\\$table, string \\\$field\): bool.*?return !empty\(\\\$GLOBALS\['TL_DCA'\]\[\\\$table\]\['fields'\]\[\\\$field\]\['inputType'\]\)/s",
            $source,
        );

        $driver = (string) file_get_contents(__DIR__ . '/../../../vendor/contao/core-bundle/contao/drivers/DC_Table.php');
        $this->assertStringContainsString(
            'DataContainer::isFieldExcluded($this->strTable, $this->strField) && !System::getContainer()->get(\'security.helper\')->isGranted(ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE',
            $driver,
        );
    }
}
