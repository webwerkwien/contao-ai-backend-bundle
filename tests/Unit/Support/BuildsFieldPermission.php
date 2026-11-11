<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Support;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\FilesModel;
use Contao\StringUtil;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Webwerkwien\ContaoAiBackendBundle\Security\FieldPermission;

/**
 * A real FieldPermission over hand-written DCA definitions (pinned to Contao's files in
 * FieldPermissionContractTest) and a voter that grants exactly the given `table::field`
 * entries, as Contao's alexf voter does for an editor.
 */
trait BuildsFieldPermission
{
    private mixed $dcaBefore = null;

    /**
     * @param array<string, array<string, mixed>> $tables table => DCA fields
     */
    private function defineDca(array $tables): void
    {
        $this->dcaBefore ??= $GLOBALS['TL_DCA'] ?? false;

        foreach ($tables as $table => $fields) {
            $GLOBALS['TL_DCA'][$table] = ['config' => ['dataContainer' => 'Contao\DC_Table'], 'fields' => $fields];
        }
    }

    private function restoreDca(): void
    {
        if (false === $this->dcaBefore) {
            unset($GLOBALS['TL_DCA']);
        } elseif (null !== $this->dcaBefore) {
            $GLOBALS['TL_DCA'] = $this->dcaBefore;
        }

        $this->dcaBefore = null;
    }

    /**
     * @param list<string> $granted `table::field` entries
     * @param list<string> $mounts  file mounts; a path in one or below one is reachable,
     *                              as Contao's file mount voter compares them
     */
    private function grantingAuthorization(array $granted, array $mounts = []): AuthorizationCheckerInterface
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(
            static fn (string $attribute, mixed $subject = null): bool => match ($attribute) {
                ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE => \in_array($subject, $granted, true),
                ContaoCorePermissions::USER_CAN_ACCESS_PATH => [] !== array_filter(
                    $mounts,
                    static fn (string $mount): bool => $subject === $mount || str_starts_with((string) $subject, $mount . '/'),
                ),
                default => false,
            },
        );

        return $auth;
    }

    /**
     * @param list<string>          $granted `table::field` entries
     * @param array<string, string> $files   string UUID => path of the files in the file manager
     * @param list<string>          $mounts  the user's file mounts
     */
    private function fieldPermission(array $granted, array $files = [], array $mounts = []): FieldPermission
    {
        $filesModel = $this->getMockBuilder(Adapter::class)->disableOriginalConstructor()->addMethods(['findByUuid'])->getMock();
        $filesModel->method('findByUuid')->willReturnCallback(static function (string $uuid) use ($files): ?object {
            $path = $files[StringUtil::binToUuid($uuid)] ?? null;

            return null === $path ? null : (object) ['path' => $path];
        });

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(
            fn (string $class): Adapter => FilesModel::class === $class ? $filesModel : $this->createMock(Adapter::class),
        );

        return new FieldPermission($framework, $this->grantingAuthorization($granted, $mounts));
    }
}
