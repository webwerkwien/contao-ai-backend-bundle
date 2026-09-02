<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\FaqModel;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;
use Webwerkwien\ContaoAiCoreBundle\Command\FaqCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FaqDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FaqReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FaqUpdateCommand;

/**
 * CRUD for FAQ entries — the second half of what {@see EventTool} closes.
 *
 * Same story: the console commands existed in contao-ai-core-bundle, the
 * permission layer existed for the cloner and the rewriter, and only the wrapper
 * was missing. An agent could rewrite an FAQ answer but not write one.
 */
#[AsTool('faq_create', 'Create a new FAQ entry inside a Contao FAQ category', method: 'create')]
#[AsTool('faq_update', 'Update one or more fields of an existing FAQ entry by ID', method: 'update')]
#[AsTool('faq_delete', 'Delete a Contao FAQ entry by ID. Requires explicit confirmation.', method: 'delete')]
#[AsTool('faq_read',   'Read a single FAQ entry by ID and return all fields', method: 'read')]
class FaqTool extends AbstractCoreCommandTool
{
    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
        private readonly ContaoFramework $framework,
        private readonly FaqCreateCommand $createCommand,
        private readonly FaqUpdateCommand $updateCommand,
        private readonly FaqDeleteCommand $deleteCommand,
        private readonly FaqReadCommand $readCommand,
    ) {
        parent::__construct($accessChecker, $tokenChecker);
    }

    public function getToolName(): string
    {
        return 'faq_create';
    }

    /**
     * Allow-list of editable FAQ fields, taken from the live DCA of `tl_faq`.
     *
     * `answer` is rich text and stays in — it is the point of an FAQ. Excluded:
     * id, tstamp, pid (use create), alias, author, sorting — identity and
     * ordering, which belong to the back end rather than to an agent.
     */
    protected function allowedFields(): array
    {
        return ['question', 'answer', 'published', 'pageTitle', 'description'];
    }

    /**
     * @param int         $pid      FAQ category ID (tl_faq_category)
     * @param string      $question The question
     * @param string|null $answer   The answer; may be omitted and filled in later
     */
    #[AiContract(
        writes: true, tables: ['tl_faq'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id'],
    )]
    public function create(int $pid, string $question, ?string $answer = null): string
    {
        $this->assertCategoryAccess($pid);

        // Inline keys — a null is dropped in runCommand(), which is what keeps
        // this call visible to ToolArgumentsMatchCommandTest.
        return $this->runCommand($this->createCommand, [
            '--question' => $question,
            '--pid'      => (string) $pid,
            '--answer'   => $answer,
        ], 'faq_create');
    }

    /**
     * @param int $id FAQ entry ID
     * @param array<string, scalar|null> $fields Object mapping field name to new value, e.g. {"question": "Wie melde ich mich an?", "published": true}. Allowed field names: question, answer, published, pageTitle, description. Pass exactly the fields that should change.
     */
    #[AiContract(
        writes: true, tables: ['tl_faq'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: true, answerShape: ['status', 'id'],
    )]
    public function update(int $id, array $fields): string
    {
        $this->assertRecordAccess($id, 'update');

        return $this->runCommand($this->updateCommand, [
            'id'    => (string) $id,
            '--set' => $this->buildSetOptions($fields),
        ], 'faq_update');
    }

    #[AiContract(
        writes: true, tables: ['tl_faq'], trace: ['tl_undo', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id', 'deleted'],
    )]
    public function delete(int $id): string
    {
        $this->assertRecordAccess($id, 'delete');

        $this->framework->initialize();
        $faq = FaqModel::findById($id);
        $question = null !== $faq && \is_string($faq->question ?? null) ? (string) $faq->question : 'unbekannt';

        $staged = $this->requireConfirmation(
            'faq_delete',
            (string) $id,
            \sprintf('Der FAQ-Eintrag "%s" (ID %d) soll endgültig gelöscht werden. Wirklich fortfahren?', $question, $id),
            [
                'id'       => $id,
                'category' => null !== $faq ? (int) $faq->pid : null,
                'question' => $question,
            ],
        );
        if (null !== $staged) {
            return $staged;
        }

        return $this->runCommand($this->deleteCommand, ['id' => (string) $id], 'faq_delete');
    }

    #[AiContract(writes: false, tables: ['tl_faq'], trace: [])]
    public function read(int $id): string
    {
        $this->assertRecordAccess($id, 'read');

        return $this->runCommand($this->readCommand, ['id' => (string) $id], 'faq_read');
    }

    /**
     * Per-record permission: an FAQ belongs to a category (pid), and the editor
     * needs access to that category.
     */
    protected function assertRecordAccess(int $recordId, string $operation): void
    {
        $user = $this->requireBackendUser();
        if ($user->isAdmin) {
            return;
        }

        $this->framework->initialize();
        $faq = FaqModel::findById($recordId);
        if (null === $faq) {
            throw new ToolRefusedException(\sprintf('FAQ-Eintrag %d nicht gefunden.', $recordId));
        }

        $this->assertCategoryAccess((int) $faq->pid);
    }

    /**
     * ⚠️ `contao_user.faqs` — plural. The permission field on the user is named
     * by the DCA's `'userRoot' => 'faqs'`, and a singular `hasAccess($id, 'faq')`
     * reads a property that does not exist and therefore denies every non-admin.
     * That is exactly what stood in `RecordPermissionChecker` until 2026-09-02.
     */
    private function assertCategoryAccess(int $categoryId): void
    {
        $user = $this->requireBackendUser();
        if ($user->isAdmin) {
            return;
        }
        if (!$this->authorizationChecker->isGranted('contao_user.faqs', [$categoryId])) {
            throw new ToolAccessDeniedException(
                \sprintf('Kein Zugriff auf FAQ-Kategorie %d.', $categoryId)
            );
        }
    }
}
