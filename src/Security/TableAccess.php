<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Security;

use Contao\BackendUser;

/**
 * Whether a back-end user may work on a table: the rule of Contao's own
 * TableAccessVoter (5.3, 5.7 and 6.0) — some back-end module lists the table, and the
 * user has that module (v0.13.0).
 *
 * For tables the tools do not know in advance: an extension's, named in the
 * `tables` of a command's `#[AiContract]`. Module rights are what the back end already
 * grants per user group, so an editor reaches through the chat what he reaches in the
 * back end — instead of a separate allow-list.
 * Admins reach every table, as in Contao.
 *
 * `hasAccess()` on the given user rather than the voter: the CLI bridge runs tools for
 * a user that is not the security token's.
 */
class TableAccess
{
    public function canAccess(BackendUser $user, string $table): bool
    {
        if ($user->isAdmin) {
            return true;
        }

        foreach ($GLOBALS['BE_MOD'] ?? [] as $modules) {
            foreach ((array) $modules as $name => $config) {
                if (\in_array($table, (array) ($config['tables'] ?? []), true) && $user->hasAccess((string) $name, 'modules')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<string> $tables
     *
     * @return list<string> the tables of $tables the user cannot reach
     */
    public function missing(BackendUser $user, array $tables): array
    {
        return array_values(array_filter($tables, fn (string $table): bool => !$this->canAccess($user, $table)));
    }
}
