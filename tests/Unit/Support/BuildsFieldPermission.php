<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Support;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
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
     * @param list<string> $granted
     */
    private function grantingAuthorization(array $granted): AuthorizationCheckerInterface
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(
            static fn (string $attribute, mixed $subject = null): bool => ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE === $attribute && \in_array($subject, $granted, true),
        );

        return $auth;
    }

    /**
     * @param list<string> $granted
     */
    private function fieldPermission(array $granted): FieldPermission
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($this->createMock(Adapter::class));

        return new FieldPermission($framework, $this->grantingAuthorization($granted));
    }
}
