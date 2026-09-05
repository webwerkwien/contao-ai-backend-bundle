<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\ArticleModel;
use Contao\BackendUser;
use Contao\ContentModel;
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
use Webwerkwien\ContaoAiCoreBundle\Command\ContentCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentUpdateCommand;

#[AsTool('content_create', 'Create a Contao content element (text, image, headline, …) inside an article or other ptable', method: 'create')]
#[AsTool('content_update', 'Update one or more fields of a Contao content element by ID', method: 'update')]
#[AsTool('content_delete', 'Delete a Contao content element by ID. Requires explicit confirmation.', method: 'delete')]
#[AsTool('content_read',   'Read a single content element by ID', method: 'read')]
class ContentTool extends AbstractCoreCommandTool
{
    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
        private readonly ContaoFramework $framework,
        private readonly ContentCreateCommand $createCommand,
        private readonly ContentUpdateCommand $updateCommand,
        private readonly ContentDeleteCommand $deleteCommand,
        private readonly ContentReadCommand $readCommand,
    ) {
        parent::__construct($accessChecker, $tokenChecker);
    }

    public function getToolName(): string
    {
        return 'content_create';
    }

    /**
     * Allow-list of editable content-element fields.
     * Excluded: id, tstamp, pid, ptable, sorting, type (recreate instead),
     * cssID, protected, groups, guests — those alter identity/placement/protection.
     * Focused on text-element fields (most common); other types may need narrower lists.
     */
    protected function allowedFields(): array
    {
        return [
            'headline', 'text', 'html', 'addImage',
            'published', 'start', 'stop',
        ];
    }

    #[AiContract(
        writes: true, tables: ['tl_content'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id'],
    )]
    public function create(string $type, int $pid, string $ptable = 'tl_article'): string
    {
        // For create, the content element doesn't exist yet — verify access via parent.
        $this->assertParentAccess($pid, $ptable);

        return $this->runCommand($this->createCommand, [
            '--type'   => $type,
            '--pid'    => (string) $pid,
            '--ptable' => $ptable,
        ], 'content_create');
    }

    /** @param array<string, scalar|null> $fields */
    #[AiContract(
        writes: true, tables: ['tl_content'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: true, answerShape: ['status', 'id'],
    )]
    public function update(int $id, array $fields): string
    {
        $this->assertRecordAccess($id, 'update');
        return $this->runCommand($this->updateCommand, [
            'id'    => (string) $id,
            '--set' => $this->buildSetOptions($fields),
        ], 'content_update');
    }

    #[AiContract(
        writes: true, tables: ['tl_content'], trace: ['tl_undo', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id', 'deleted'],
    )]
    public function delete(int $id): string
    {
        $this->assertRecordAccess($id, 'delete');

        $this->framework->initialize();
        $element = \Contao\ContentModel::findById($id);
        $type = null !== $element ? (string) ($element->type ?? 'unbekannt') : 'unbekannt';
        $stagePayload = ['id' => $id, 'type' => $type, 'pid' => null !== $element ? (int) $element->pid : null];
        $staged = $this->requireConfirmation(
            'content_delete',
            (string) $id,
            \sprintf('Das Inhaltselement (ID %d, Typ "%s") soll endgültig gelöscht werden. Wirklich fortfahren?', $id, $type),
            $stagePayload,
        );
        if (null !== $staged) {
            return $staged;
        }

        return $this->runCommand($this->deleteCommand, ['id' => (string) $id], 'content_delete');
    }

    #[AiContract(writes: false, tables: ['tl_content'], trace: [])]
    public function read(int $id): string
    {
        $this->assertRecordAccess($id, 'read');
        return $this->runCommand($this->readCommand, ['id' => (string) $id], 'content_read');
    }

    /**
     * Per-record permission: content element -> parent (article or other ptable).
     * For tl_article parent: lookup article -> page -> CAN_EDIT_ARTICLES.
     * For other ptables: fall back to admin-only (we don't know the permission model).
     */
    protected function assertRecordAccess(int $recordId, string $operation): void
    {
        $user = $this->requireBackendUser();
        if ($user->isAdmin) {
            return;
        }

        $this->framework->initialize();
        $content = ContentModel::findById($recordId);
        if (null === $content) {
            throw new ToolRefusedException(\sprintf('Inhaltselement %d nicht gefunden.', $recordId));
        }
        $this->assertParentAccess((int) $content->pid, (string) $content->ptable);
    }

    private function assertParentAccess(int $pid, string $ptable): void
    {
        $user = $this->requireBackendUser();
        if ($user->isAdmin) {
            return;
        }

        if ('tl_article' !== $ptable) {
            // Other parent tables (news, calendar_events, …) have their own permission models
            // that are not implemented here. Default-deny for non-admins.
            throw new ToolAccessDeniedException(
                \sprintf('Inhaltselemente in der Tabelle "%s" können nur von Admins verwaltet werden.', $ptable)
            );
        }

        $this->framework->initialize();
        $article = ArticleModel::findById($pid);
        if (null === $article) {
            throw new ToolRefusedException(\sprintf('Artikel %d nicht gefunden.', $pid));
        }
        $page = PageModel::findById((int) $article->pid);
        if (null === $page) {
            throw new ToolRefusedException(\sprintf('Übergeordnete Seite zu Artikel %d nicht gefunden.', $pid));
        }
        if (!$this->authorizationChecker->isGranted(ContaoCorePermissions::USER_CAN_EDIT_ARTICLES, $page->row())) {
            throw new ToolAccessDeniedException(
                \sprintf('Kein Zugriff auf Artikel %d für Inhaltselement-Operationen.', $pid)
            );
        }
    }
}
