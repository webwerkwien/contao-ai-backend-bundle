<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Security;

use Contao\BackendUser;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;

/**
 * Record rights for the macro tools (record_clone, record_rewrite, language_link).
 *
 * Since v0.18.0 a thin layer over DataContainerAccess: Contao's data container voters
 * decide, asked like DC_Table asks them. Until then this class rebuilt them by hand —
 * module plus archive (`contao_user.news` …) or page right (`USER_CAN_EDIT_PAGE` …) —
 * and admins skipped every check. Both are gone: the voters judge admins too, as in the
 * back end, and they know what the rebuild did not (page and element types, the page
 * hierarchy, create and delete rights, voters of extensions).
 *
 * What stays ours: the tables the macro tools support, and that a content element must
 * sit below an article, news or event (DataContainerAccess::contentParentDenial()).
 */
class RecordPermissionChecker
{
    private const TABLES = [
        'tl_news_archive', 'tl_news',
        'tl_calendar', 'tl_calendar_events',
        'tl_faq_category', 'tl_faq',
        'tl_page', 'tl_article', 'tl_content',
    ];

    private const CONTAINER_TABLES = ['tl_news_archive', 'tl_calendar', 'tl_faq_category', 'tl_page'];

    public function __construct(
        private readonly DataContainerAccess $dcAccess,
    ) {
    }

    /**
     * @param string $operation 'read' | 'edit' | 'delete'
     *
     * @throws ToolAccessDeniedException
     * @throws ToolRefusedException when the record does not exist
     */
    public function assertRecordAccess(BackendUser $user, string $table, int $id, string $operation): void
    {
        $denial = $this->checkRecordAccess($user, $table, $id, $operation);
        if (null !== $denial) {
            throw new ToolAccessDeniedException($denial);
        }
    }

    /**
     * Soft variant for recursive paths: null when allowed, otherwise a short reason the
     * caller puts into its `refused` list instead of stopping.
     */
    public function recordAccessDenialReason(BackendUser $user, string $table, int $id, string $operation): ?string
    {
        return $this->checkRecordAccess($user, $table, $id, $operation);
    }

    /**
     * May the user copy this record — the question DC_Table asks when a record goes to
     * the clipboard for copying (`CreateAction` with the record, `sorting` null). For a
     * page: edit or hierarchy right on it, access to it, and the right to create pages.
     */
    public function recordCopyDenialReason(BackendUser $user, string $table, int $id): ?string
    {
        if (!\in_array($table, self::TABLES, true)) {
            return \sprintf('Tabelle "%s" wird nicht unterstützt.', $table);
        }
        $record = $this->dcAccess->fetchRecord($table, $id);
        if (null === $record) {
            return \sprintf('Datensatz %d in %s nicht gefunden.', $id, $table);
        }

        return $this->dcAccess->denialReason(
            new CreateAction($table, array_replace($record, ['tstamp' => null, 'sorting' => null])),
            $id,
        );
    }

    /**
     * May the user create the clone of a container: the copy lands next to its source,
     * so the voters get the source record without its id, as DC_Table's paste does. For
     * a page that means edit and hierarchy rights on the parent; a root (pid 0) stays
     * with admins because no editor has a mount above it.
     *
     * @throws ToolAccessDeniedException
     * @throws ToolRefusedException when the source does not exist
     */
    public function assertCanCreateContainer(BackendUser $user, string $sourceTable, int $sourceId): void
    {
        if (!\in_array($sourceTable, self::CONTAINER_TABLES, true)) {
            throw new ToolAccessDeniedException(\sprintf(
                'Container-Anlage für "%s" wird vom Permission-Checker nicht unterstützt.',
                $sourceTable
            ));
        }

        $source = $this->dcAccess->fetchRecord($sourceTable, $sourceId);
        if (null === $source) {
            throw new ToolRefusedException(\sprintf('Quelle %d in %s nicht gefunden.', $sourceId, $sourceTable));
        }

        unset($source['id']);
        $source['tstamp'] = 0;
        // `sorting` stays the source's (non-null): null would read as a copy to the
        // clipboard, which the page voter judges on the source instead of the parent.
        if (\array_key_exists('sorting', $source) && null === $source['sorting']) {
            $source['sorting'] = 0;
        }

        $this->dcAccess->assertCreate($sourceTable, $source);
    }

    private function checkRecordAccess(BackendUser $user, string $table, int $id, string $operation): ?string
    {
        if (!\in_array($table, self::TABLES, true)) {
            return \sprintf('Tabelle "%s" wird nicht unterstützt.', $table);
        }

        $record = $this->dcAccess->fetchRecord($table, $id);
        if (null === $record) {
            return \sprintf('Datensatz %d in %s nicht gefunden.', $id, $table);
        }

        $action = match ($operation) {
            'read'   => new ReadAction($table, $record),
            'delete' => new DeleteAction($table, $record),
            default  => new UpdateAction($table, $record),
        };

        $denial = $this->dcAccess->denialReason($action, $id);
        if (null !== $denial || 'tl_content' !== $table || $user->isAdmin) {
            return $denial;
        }

        return $this->dcAccess->contentParentDenial($record);
    }
}
