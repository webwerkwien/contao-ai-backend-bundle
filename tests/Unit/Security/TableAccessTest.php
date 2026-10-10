<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Security;

use Contao\BackendUser;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Security\TableAccess;

/**
 * A table is reachable through a back-end module that lists it and that the user has
 * — the rule of Contao's TableAccessVoter (v0.13.0).
 */
class TableAccessTest extends TestCase
{
    private mixed $beMod = null;

    protected function setUp(): void
    {
        $this->beMod      = $GLOBALS['BE_MOD'] ?? null;
        $GLOBALS['BE_MOD'] = [
            'consho' => [
                'consho_product' => ['tables' => ['tl_consho_product', 'tl_consho_price']],
                'consho_order'   => ['tables' => ['tl_consho_order']],
            ],
        ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['BE_MOD'] = $this->beMod;
    }

    /**
     * @param list<string> $modules
     */
    private function user(bool $admin, array $modules): BackendUser
    {
        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => 'isAdmin' === $key ? $admin : null);
        $user->method('hasAccess')->willReturnCallback(static fn (string $module, string $field): bool => 'modules' === $field && \in_array($module, $modules, true));

        return $user;
    }

    public function testAModuleThatListsTheTableGivesAccess(): void
    {
        $access = new TableAccess();
        $editor = $this->user(false, ['consho_product']);

        $this->assertTrue($access->canAccess($editor, 'tl_consho_price'));
        $this->assertFalse($access->canAccess($editor, 'tl_consho_order'));
        $this->assertSame(['tl_consho_order'], $access->missing($editor, ['tl_consho_product', 'tl_consho_order']));
    }

    public function testATableNoModuleListsIsForAdminsOnly(): void
    {
        $access = new TableAccess();

        $this->assertFalse($access->canAccess($this->user(false, ['consho_product', 'consho_order']), 'tl_consho_redirect'));
        $this->assertTrue($access->canAccess($this->user(true, []), 'tl_consho_redirect'));
    }
}
