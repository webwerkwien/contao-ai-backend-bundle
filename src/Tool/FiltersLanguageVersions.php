<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\CoreBundle\Security\DataContainer\ReadAction;

/**
 * Linked language versions and website roots, as core v1.11.0 names them, reduced to what
 * the user may read (v0.21.0). The core trusts its operator; a version on a page outside
 * the user's mounts would otherwise reach the model with its title. Each entry is voted
 * on as a read of that record, like content_find votes on its hits.
 *
 * @property \Webwerkwien\ContaoAiBackendBundle\Security\DataContainerAccess $dcAccess
 */
trait FiltersLanguageVersions
{
    /**
     * @return array{list<array<string, mixed>>, int} the readable entries, and how many were left out
     */
    private function readable(string $table, mixed $entries): array
    {
        $kept = [];
        $hidden = 0;
        foreach (\is_array($entries) ? $entries : [] as $entry) {
            $record = \is_array($entry) ? $this->dcAccess->fetchRecord($table, (int) ($entry['id'] ?? 0)) : null;
            if (null !== $record && $this->dcAccess->isGranted(new ReadAction($table, $record))) {
                $kept[] = $entry;
            } else {
                ++$hidden;
            }
        }

        return [$kept, $hidden];
    }
}
