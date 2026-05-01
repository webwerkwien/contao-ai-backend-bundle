<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\ArticleModel;
use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\PageModel;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleUpdateCommand;

#[AsTool('article_create', 'Create a Contao article inside a page column', method: 'create')]
#[AsTool('article_update', 'Update one or more fields of a Contao article by ID', method: 'update')]
#[AsTool('article_delete', 'Delete a Contao article by ID. Requires explicit confirmation.', method: 'delete')]
#[AsTool('article_read',   'Read a single article by ID', method: 'read')]
class ArticleTool extends AbstractCoreCommandTool
{
    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
        private readonly ContaoFramework $framework,
        private readonly ArticleCreateCommand $createCommand,
        private readonly ArticleUpdateCommand $updateCommand,
        private readonly ArticleDeleteCommand $deleteCommand,
        private readonly ArticleReadCommand $readCommand,
    ) {
        parent::__construct($accessChecker, $tokenChecker);
    }

    public function getToolName(): string
    {
        return 'article_create';
    }

    /**
     * Allow-list of editable article fields.
     * Excluded: id, tstamp, pid, sorting, alias, inColumn, cssID, protected,
     * groups, guests, printable — those alter placement/visibility/protection.
     */
    protected function allowedFields(): array
    {
        return [
            'title', 'teaser', 'showTeaser', 'keywords',
            'published', 'start', 'stop',
        ];
    }

    public function create(string $title, int $pid, string $inColumn = 'main'): string
    {
        // Articles live under a page — verify CAN_EDIT_ARTICLES on the parent page.
        $this->assertPageAccess($pid);

        return $this->runCommand($this->createCommand, [
            '--title'    => $title,
            '--pid'      => (string) $pid,
            '--inColumn' => $inColumn,
        ], 'article_create');
    }

    public function update(int $id, array $fields): string
    {
        $this->assertRecordAccess($id, 'update');
        return $this->runCommand($this->updateCommand, [
            'id'    => (string) $id,
            '--set' => $this->buildSetOptions($fields),
        ], 'article_update');
    }

    public function delete(int $id): string
    {
        $this->assertRecordAccess($id, 'delete');

        $this->framework->initialize();
        $article = \Contao\ArticleModel::findById($id);
        $title = null !== $article ? (string) ($article->title ?? 'unbekannt') : 'unbekannt';
        $stagePayload = ['id' => $id, 'title' => $title, 'pid' => null !== $article ? (int) $article->pid : null];
        $staged = $this->requireConfirmation(
            'article_delete',
            (string) $id,
            \sprintf('Der Artikel "%s" (ID %d) soll endgültig gelöscht werden. Wirklich fortfahren?', $title, $id),
            $stagePayload,
        );
        if (null !== $staged) {
            return $staged;
        }

        return $this->runCommand($this->deleteCommand, ['id' => (string) $id], 'article_delete');
    }

    public function read(int $id): string
    {
        $this->assertRecordAccess($id, 'read');
        return $this->runCommand($this->readCommand, ['id' => (string) $id], 'article_read');
    }

    /**
     * Per-record permission: article -> parent page; check CAN_EDIT_ARTICLES on the page.
     */
    protected function assertRecordAccess(int $recordId, string $operation): void
    {
        $user = $this->requireBackendUser();
        if ($user->isAdmin) {
            return;
        }

        $this->framework->initialize();
        $article = ArticleModel::findById($recordId);
        if (null === $article) {
            throw new ToolExecutionException(\sprintf('Artikel %d nicht gefunden.', $recordId));
        }
        $this->assertPageAccess((int) $article->pid);
    }

    private function assertPageAccess(int $pageId): void
    {
        $user = $this->requireBackendUser();
        if ($user->isAdmin) {
            return;
        }

        $this->framework->initialize();
        $page = PageModel::findById($pageId);
        if (null === $page) {
            throw new ToolExecutionException(\sprintf('Übergeordnete Seite %d nicht gefunden.', $pageId));
        }
        if (!$this->authorizationChecker->isGranted(ContaoCorePermissions::USER_CAN_EDIT_ARTICLES, $page->row())) {
            throw new ToolAccessDeniedException(
                \sprintf('Kein Zugriff auf Artikel von Seite %d.', $pageId)
            );
        }
    }
}
