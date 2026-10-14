<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Security;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\DC_File;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Security\FieldPermission;

/**
 * The back end's field rights ("Erlaubte Felder") for the chat (v0.17.0).
 *
 * Until then an editor whose group grants no page field — the demo group "Editors" on
 * the test server grants none — could publish, rename and re-language pages through the
 * chat. The rule is Contao's: excluded (every field with an input, unless the DCA says
 * `'exclude' => false`) and not granted means not writable.
 */
class FieldPermissionTest extends TestCase
{
    private mixed $dcaBefore;

    protected function setUp(): void
    {
        $this->dcaBefore = $GLOBALS['TL_DCA'] ?? null;

        // As in Contao 5.7/6.0; the shapes are pinned to the real files in FieldPermissionContractTest.
        $GLOBALS['TL_DCA']['tl_page'] = [
            'config' => ['dataContainer' => 'Contao\DC_Table'],
            'fields' => [
                'title'     => ['inputType' => 'text', 'sql' => "varchar(255) NOT NULL default ''"],
                'type'      => ['inputType' => 'select', 'sql' => "varchar(64) NOT NULL default 'regular'"],
                'published' => ['inputType' => 'checkbox', 'sql' => ['type' => 'boolean', 'default' => false]],
                'robotsTxt' => ['inputType' => 'textarea', 'sql' => 'text NULL'],
                'open'      => ['inputType' => 'text', 'exclude' => false, 'sql' => "varchar(8) NOT NULL default ''"],
                'useSSL'    => ['inputType' => 'checkbox', 'sql' => ['type' => 'boolean', 'default' => true]],
                'sorting'   => ['inputType' => 'text', 'sql' => 'int(10) unsigned NOT NULL default 0'],
                'pid'       => ['sql' => 'int(10) unsigned NOT NULL default 0'],
            ],
        ];
        $GLOBALS['TL_DCA']['tl_content']['fields']['type'] = [
            'inputType' => 'select',
            'sql'       => ['name' => 'type', 'type' => 'string', 'length' => 64, 'default' => 'text'],
        ];
        $GLOBALS['TL_DCA']['tl_news']['fields']['date'] = ['inputType' => 'text', 'default' => 1760000000, 'sql' => 'int(10) unsigned NOT NULL default 0'];
        $GLOBALS['TL_DCA']['tl_news']['fields']['headline'] = ['inputType' => 'text', 'sql' => "varchar(255) NOT NULL default ''"];
        $GLOBALS['TL_DCA']['tl_settings'] = [
            'config' => ['dataContainer' => DC_File::class],
            'fields' => ['adminEmail' => ['inputType' => 'text']],
        ];
    }

    protected function tearDown(): void
    {
        if (null === $this->dcaBefore) {
            unset($GLOBALS['TL_DCA']);
        } else {
            $GLOBALS['TL_DCA'] = $this->dcaBefore;
        }
    }

    /**
     * @param list<string>               $granted `table::field` entries of the user's groups
     * @param \ArrayObject<int, string>|null $loaded  collects the tables whose DCA was loaded
     */
    private function permission(array $granted = [], ?\ArrayObject $loaded = null): FieldPermission
    {
        $loaded ??= $this->recorder();
        $controller = new class($loaded) {
            /** @param \ArrayObject<int, string> $loaded */
            public function __construct(private readonly \ArrayObject $loaded)
            {
            }

            public function loadDataContainer(string $table): void
            {
                $this->loaded->append($table);
            }
        };

        $adapter = $this->createMock(Adapter::class);
        $adapter->method('__call')->willReturnCallback(static fn (string $method, array $args) => $controller->{$method}(...$args));

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($adapter);

        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(
            static fn (string $attribute, mixed $subject = null): bool => ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE === $attribute && \in_array($subject, $granted, true),
        );

        return new FieldPermission($framework, $auth);
    }

    /**
     * @return \ArrayObject<int, string>
     */
    private function recorder(): \ArrayObject
    {
        return new \ArrayObject();
    }

    private function user(bool $admin = false): BackendUser
    {
        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => 'isAdmin' === $key ? $admin : null);

        return $user;
    }

    public function testAnEditorWithoutTheRightCannotPublish(): void
    {
        $this->assertSame(['published'], $this->permission()->denied($this->user(), 'tl_page', ['published']));
    }

    public function testTheGrantedFieldPassesAndTheOthersAreNamed(): void
    {
        $this->assertSame(
            ['title'],
            $this->permission(['tl_page::published'])->denied($this->user(), 'tl_page', ['published', 'title']),
        );
    }

    public function testAnAdminNeedsNoRight(): void
    {
        $this->assertSame([], $this->permission()->denied($this->user(true), 'tl_page', ['published', 'title', 'type']));
    }

    /**
     * Contao's exceptions to "excluded": a DCA that opens the field, a field without an
     * input (not editable in the back end, written by the chat only as a link like pid),
     * and DC_File, where Contao never applies field rights.
     */
    public function testWhatContaoDoesNotExcludeNeedsNoRight(): void
    {
        $permission = $this->permission();

        $this->assertSame([], $permission->denied($this->user(), 'tl_page', ['open', 'pid']));
        $this->assertSame([], $permission->denied($this->user(), 'tl_settings', ['adminEmail']));
    }

    public function testTheTablesDcaIsLoadedFirst(): void
    {
        $loaded = $this->recorder();
        $this->permission([], $loaded)->denied($this->user(), 'tl_page', ['published']);

        $this->assertSame(['tl_page'], $loaded->getArrayCopy());
    }

    public function testTheRefusalNamesEveryFieldAndSaysNothingWasWritten(): void
    {
        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessageMatches('/tl_page\.published, tl_page\.title.*Erlaubte Felder.*nichts geschrieben/s');

        $this->permission()->assertWritable($this->user(), 'tl_page', ['published', 'title']);
    }

    /**
     * As in the back end, a new record gets its defaults without any right: the column
     * default (`type` 'regular' as SQL string, tl_content `type` 'text' as SQL array), the
     * empty boolean in any of its spellings, and a value that is not sent at all.
     */
    public function testCreatingWithTheDefaultsNeedsNoRight(): void
    {
        $permission = $this->permission();

        $this->assertSame([], $permission->deniedOnCreate($this->user(), 'tl_page', ['type' => 'regular', 'published' => '', 'title' => '', 'robotsTxt' => null]));
        $this->assertSame([], $permission->deniedOnCreate($this->user(), 'tl_page', ['published' => '0']));
        $this->assertSame([], $permission->deniedOnCreate($this->user(), 'tl_content', ['type' => 'text']));
        $this->assertSame([], $permission->deniedOnCreate($this->user(), 'tl_news', ['date' => 1760000000]));
    }

    /**
     * Closed where the rule cannot be read (review 2026-10-14): a field the DCA does not
     * know, and a table whose DCA did not load — isFieldExcluded() would say "not
     * excluded" for both and let the write through.
     */
    public function testAFieldTheDcaDoesNotKnowIsDenied(): void
    {
        $this->assertSame(['keywords'], $this->permission(['tl_page::keywords'])->denied($this->user(), 'tl_page', ['keywords']));
    }

    public function testATableWithoutDcaRefusesEverything(): void
    {
        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessageMatches('/tl_unknown.*keine DCA/');

        $this->permission()->denied($this->user(), 'tl_unknown', ['title']);
    }

    /**
     * The right asked for is the one of the table written to, not of tl_page.
     */
    public function testTheRightIsAskedForTheTableWrittenTo(): void
    {
        $permission = $this->permission(['tl_news::headline']);

        $this->assertSame([], $permission->denied($this->user(), 'tl_news', ['headline']));
        $this->assertSame(['title'], $permission->denied($this->user(), 'tl_page', ['title']));
    }

    /**
     * The column default when neither the DCA nor a quoted SQL default names one: the
     * empty value of the type (0 for an integer), and true for a boolean declared so.
     */
    public function testTheUnquotedAndTheTrueDefaultsAreRecognised(): void
    {
        $permission = $this->permission();

        $this->assertSame([], $permission->deniedOnCreate($this->user(), 'tl_page', ['sorting' => '0', 'useSSL' => '1']));
        $this->assertSame(['sorting', 'useSSL'], $permission->deniedOnCreate($this->user(), 'tl_page', ['sorting' => '128', 'useSSL' => '']));
    }

    public function testCreatingWithOtherValuesNeedsTheRight(): void
    {
        $permission = $this->permission(['tl_page::title']);

        $this->assertSame(['type', 'published'], $permission->deniedOnCreate($this->user(), 'tl_page', ['title' => 'Über uns', 'type' => 'forward', 'published' => '1']));
        $this->assertSame(['type'], $permission->deniedOnCreate($this->user(), 'tl_content', ['type' => 'html']));
        $this->assertSame(['date'], $permission->deniedOnCreate($this->user(), 'tl_news', ['date' => '2026-10-14']));
    }

    public function testACreateRefusalSaysWhichFields(): void
    {
        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessageMatches('/das Feld: tl_page\.published\./');

        $this->permission()->assertCreatable($this->user(), 'tl_page', ['published' => '1', 'type' => 'regular']);
    }
}
