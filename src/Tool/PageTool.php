<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\PageModel;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiCoreBundle\Command\PageCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PagePublishCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageUpdateCommand;

#[AsTool('page_create',  'Create a new Contao page (sub-page or root)', method: 'create')]
#[AsTool('page_update',  'Update one or more fields of a Contao page by ID', method: 'update')]
#[AsTool('page_delete',  'Delete a Contao page by ID. Requires explicit confirmation.', method: 'delete')]
#[AsTool('page_read',    'Read a single page by ID', method: 'read')]
#[AsTool('page_publish', 'Publish or unpublish a Contao page by ID', method: 'publish')]
class PageTool extends AbstractCoreCommandTool
{
    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
        private readonly ContaoFramework $framework,
        private readonly PageCreateCommand $createCommand,
        private readonly PageUpdateCommand $updateCommand,
        private readonly PageDeleteCommand $deleteCommand,
        private readonly PageReadCommand $readCommand,
        private readonly PagePublishCommand $publishCommand,
    ) {
        parent::__construct($accessChecker, $tokenChecker);
    }

    public function getToolName(): string
    {
        return 'page_create';
    }

    /**
     * Allow-list of editable page fields.
     * Excluded: id, tstamp, pid, sorting, alias (rename via create+delete),
     * type, layout, includeLayout, includeChmod, chmod, cuser, cgroup, fallback,
     * cssClass, robots — those alter routing/permissions/visual layer.
     */
    protected function allowedFields(): array
    {
        return [
            'title', 'pageTitle', 'language', 'description', 'keywords',
            'published', 'start', 'stop',
        ];
    }

    #[AiContract(
        writes: true, tables: ['tl_page'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id'],
    )]
    public function create(string $title, string $alias, string $type, int $pid): string
    {
        // For create, the new page does not exist yet — verify the user is
        // allowed to add a sub-page below the parent (CAN_EDIT_PAGE on parent).
        if (0 !== $pid) {
            $this->assertPageOperation($pid, ContaoCorePermissions::USER_CAN_EDIT_PAGE_HIERARCHY);
        } else {
            // Root pages: admin only.
            $user = $this->requireBackendUser();
            if (!$user->isAdmin) {
                throw new ToolAccessDeniedException('Root-Seiten anlegen ist nur für Admins erlaubt.');
            }
        }

        return $this->runCommand($this->createCommand, [
            '--title' => $title,
            '--alias' => $alias,
            '--type'  => $type,
            '--pid'   => (string) $pid,
        ], 'page_create');
    }

    /** @param array<string, scalar|null> $fields */
    #[AiContract(
        writes: true, tables: ['tl_page'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: true, answerShape: ['status', 'id'],
    )]
    public function update(int $id, array $fields): string
    {
        $this->assertRecordAccess($id, 'update');
        return $this->runCommand($this->updateCommand, [
            'id'    => (string) $id,
            '--set' => $this->buildSetOptions($fields),
        ], 'page_update');
    }

    #[AiContract(
        writes: true, tables: ['tl_page'], trace: ['tl_undo', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id', 'deleted'],
    )]
    public function delete(int $id): string
    {
        $this->assertRecordAccess($id, 'delete');

        $this->framework->initialize();
        $page = PageModel::findById($id);
        $title = null !== $page ? (string) ($page->title ?? 'unbekannt') : 'unbekannt';
        $stagePayload = ['id' => $id, 'title' => $title, 'pid' => null !== $page ? (int) $page->pid : null];
        $staged = $this->requireConfirmation(
            'page_delete',
            (string) $id,
            \sprintf('Die Seite "%s" (ID %d) und alle Unterseiten sollen endgültig gelöscht werden. Wirklich fortfahren?', $title, $id),
            $stagePayload,
        );
        if (null !== $staged) {
            return $staged;
        }

        return $this->runCommand($this->deleteCommand, ['id' => (string) $id], 'page_delete');
    }

    #[AiContract(writes: false, tables: ['tl_page'], trace: [])]
    public function read(int $id): string
    {
        $this->assertRecordAccess($id, 'read');
        return $this->runCommand($this->readCommand, ['id' => (string) $id], 'page_read');
    }

    #[AiContract(
        writes: true, tables: ['tl_page'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: true, answerShape: ['status', 'id'],
    )]
    public function publish(int $id, bool $published): string
    {
        $this->assertRecordAccess($id, 'publish');

        // Only when taking the page offline. Publishing adds something the
        // owner can see and undo in the same breath; unpublishing removes a
        // live page from every visitor at once — and until 2026-09-02 it ran
        // without asking, while AbstractCoreCommandTool's own docblock said the
        // gate covered "delete, unpublish". The promise was there, the call was
        // not, and nothing failed because nothing tested it.
        if (!$published) {
            $staged = $this->requireConfirmation(
                'page_publish',
                (string) $id,
                \sprintf('Seite %d wirklich offline nehmen? Sie ist danach für Besucher nicht mehr erreichbar.', $id),
                ['id' => $id, 'action' => 'unpublish'],
            );

            if (null !== $staged) {
                return $staged;
            }
        }

        // `contao:page:publish` takes two positional arguments — id and
        // "publish"/"unpublish" — and has never had a `--published` option.
        // This tool sent one anyway, so page_publish failed in *both*
        // directions with *The "--published" option does not exist*. It went
        // unnoticed until 2026-09-02 because nothing had ever reached it: no
        // test covered the tool, and the first live attempt in the chat was the
        // one that finally ran into it.
        return $this->runCommand($this->publishCommand, [
            'id'     => (string) $id,
            'action' => $published ? 'publish' : 'unpublish',
        ], 'page_publish');
    }

    /**
     * Per-record permission via Symfony Security voters with the
     * ContaoCorePermissions::USER_CAN_* string subjects. Reads the page row
     * first, then asks the voter — same path the regular Contao 5 backend
     * uses for every page operation. Replaces the legacy
     * BackendUser::isAllowed(CAN_*) call which no longer exists in Contao 5.
     */
    protected function assertRecordAccess(int $recordId, string $operation): void
    {
        $user = $this->requireBackendUser();
        if ($user->isAdmin) {
            return;
        }

        $this->framework->initialize();
        $page = PageModel::findById($recordId);
        if (null === $page) {
            throw new ToolRefusedException(\sprintf('Seite %d nicht gefunden.', $recordId));
        }

        // ContaoCorePermissions strings — Contao 5 voter-based permission system.
        $permission = match ($operation) {
            'delete'           => ContaoCorePermissions::USER_CAN_DELETE_PAGE,
            'update', 'publish', 'read' => ContaoCorePermissions::USER_CAN_EDIT_PAGE,
            default            => ContaoCorePermissions::USER_CAN_EDIT_PAGE,
        };
        $this->assertPageOperation($recordId, $permission);
    }

    private function assertPageOperation(int $pageId, string $permission): void
    {
        $user = $this->requireBackendUser();
        if ($user->isAdmin) {
            return;
        }

        $this->framework->initialize();
        $page = PageModel::findById($pageId);
        if (null === $page) {
            throw new ToolRefusedException(\sprintf('Seite %d nicht gefunden.', $pageId));
        }
        if (!$this->authorizationChecker->isGranted($permission, $page->row())) {
            throw new ToolAccessDeniedException(
                \sprintf('Kein Zugriff auf Seite %d für diese Operation.', $pageId)
            );
        }
    }
}
