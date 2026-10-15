<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Security;

use Contao\BackendUser;
use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * A record an editor just created joins the allow-lists Contao itself would write — as
 * `UserRootListener::adjustPermissions()` does after a create or copy in the back end:
 * the user's groups unless the rights are `custom`, the user's own list unless they come
 * from the group only (`inherit = group`, where BackendUser ignores the user's values).
 *
 * Without it the editor would create a record they cannot see. Contao's listener needs the
 * id in the session's `new_records`, which a chat or console write does not set, so the
 * tools do it themselves (v0.19.0; first in RecordCloneTool, shared since the image sizes).
 */
final class AllowListGrant
{
    /**
     * @param string $field the allow-list field of tl_user/tl_user_group (`userRoot` of the DCA)
     *
     * @return array{user_id: int, username: string, field: string, added_id: int, user_level: bool, groups: list<int>}|null
     *               what was written; null for admins, when the merged list has it already,
     *               or when there was nowhere to write
     */
    public static function grant(Connection $connection, BackendUser $user, string $field, int $newId): ?array
    {
        if ($user->isAdmin || 0 === $newId || !preg_match('/^[a-zA-Z]+$/', $field)) {
            return null;
        }

        // The merged list first: a group that already grants the record needs nothing.
        $root = array_map('intval', (array) StringUtil::deserialize($user->{$field}, true));
        if (\in_array($newId, $root, true)) {
            return null;
        }

        $inherit = (string) ($user->inherit ?? '');
        $groups  = [];
        if ('custom' !== $inherit) {
            $groupIds = array_map('intval', (array) StringUtil::deserialize($user->groups, true));
            if ([] !== $groupIds) {
                $rows = $connection->fetchAllAssociative(
                    'SELECT id, ' . $field . ' FROM tl_user_group WHERE id IN (?)',
                    [$groupIds],
                    [ArrayParameterType::INTEGER],
                );
                foreach ($rows as $row) {
                    self::add($connection, 'tl_user_group', $row, $field, $newId);
                    $groups[] = (int) $row['id'];
                }
            }
        }

        $userLevel = false;
        if ('group' !== $inherit) {
            $row = $connection->fetchAssociative('SELECT id, ' . $field . ' FROM tl_user WHERE id = ?', [(int) $user->id]);
            if (false !== $row) {
                self::add($connection, 'tl_user', $row, $field, $newId);
                $userLevel = true;
            }
        }

        if (!$userLevel && [] === $groups) {
            return null;
        }

        // The rest of this request — the chat may act on the record in the same turn.
        $root[] = $newId;
        $user->{$field} = $root;

        // A group entry reaches everyone in that group, as in the back end; the answer says so.
        return [
            'user_id'    => (int) $user->id,
            'username'   => (string) $user->username,
            'field'      => $field,
            'added_id'   => $newId,
            'user_level' => $userLevel,
            'groups'     => $groups,
        ];
    }

    /**
     * @param array<string, mixed> $row `id` and the allow-list field
     */
    private static function add(Connection $connection, string $table, array $row, string $field, int $newId): void
    {
        $list = (array) StringUtil::deserialize($row[$field] ?? null, true);
        $list[] = (string) $newId;
        $connection->update($table, [$field => serialize($list)], ['id' => (int) $row['id']]);
    }
}
