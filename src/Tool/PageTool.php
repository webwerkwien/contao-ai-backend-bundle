<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\PageModel;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
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

    public function create(string $title, string $alias, string $type, int $pid): string
    {
        // For create, the new page does not exist yet — verify the user is
        // allowed to add a sub-page below the parent (CAN_EDIT_PAGE on parent).
        if (0 !== $pid) {
            $this->assertPageOperation($pid, BackendUser::CAN_EDIT_PAGE_HIERARCHY);
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

    public function update(int $id, array $fields): string
    {
        $this->assertRecordAccess($id, 'update');
        return $this->runCommand($this->updateCommand, [
            'id'    => (string) $id,
            '--set' => $this->buildSetOptions($fields),
        ], 'page_update');
    }

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

    public function read(int $id): string
    {
        $this->assertRecordAccess($id, 'read');
        return $this->runCommand($this->readCommand, ['id' => (string) $id], 'page_read');
    }

    public function publish(int $id, bool $published): string
    {
        $this->assertRecordAccess($id, 'publish');
        return $this->runCommand($this->publishCommand, [
            'id'          => (string) $id,
            '--published' => $published ? '1' : '0',
        ], 'page_publish');
    }

    /**
     * Per-record permission via BackendUser::isAllowed() with the appropriate
     * Contao operation flag. Reads the page row first, then asks the user
     * voter — same path the regular backend uses for every page operation.
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
            throw new ToolExecutionException(\sprintf('Seite %d nicht gefunden.', $recordId));
        }

        $flag = match ($operation) {
            'delete'           => BackendUser::CAN_DELETE_PAGE,
            'update', 'publish'=> BackendUser::CAN_EDIT_PAGE,
            'read'             => BackendUser::CAN_EDIT_PAGE,
            default            => BackendUser::CAN_EDIT_PAGE,
        };
        $this->assertPageOperation($recordId, $flag);
    }

    private function assertPageOperation(int $pageId, int $flag): void
    {
        $user = $this->requireBackendUser();
        if ($user->isAdmin) {
            return;
        }

        $this->framework->initialize();
        $page = PageModel::findById($pageId);
        if (null === $page) {
            throw new ToolExecutionException(\sprintf('Seite %d nicht gefunden.', $pageId));
        }
        if (!$user->isAllowed($flag, $page->row())) {
            throw new ToolAccessDeniedException(
                \sprintf('Kein Zugriff auf Seite %d für diese Operation.', $pageId)
            );
        }
    }
}
