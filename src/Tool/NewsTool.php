<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\NewsModel;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiCoreBundle\Command\NewsCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\NewsDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\NewsReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\NewsUpdateCommand;

#[AsTool('news_create', 'Create a new Contao news entry inside a news archive', method: 'create')]
#[AsTool('news_update', 'Update one or more fields of an existing news entry by ID', method: 'update')]
#[AsTool('news_delete', 'Delete a Contao news entry by ID. Requires explicit confirmation.', method: 'delete')]
#[AsTool('news_read',   'Read a single news entry by ID and return all fields', method: 'read')]
class NewsTool extends AbstractCoreCommandTool
{
    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
        private readonly ContaoFramework $framework,
        private readonly NewsCreateCommand $createCommand,
        private readonly NewsUpdateCommand $updateCommand,
        private readonly NewsDeleteCommand $deleteCommand,
        private readonly NewsReadCommand $readCommand,
    ) {
        parent::__construct($accessChecker, $tokenChecker);
    }

    public function getToolName(): string
    {
        return 'news_create';
    }

    /**
     * Allow-list of editable news fields.
     * Excluded by design: id, tstamp, pid (use create instead), alias, author,
     * cssClass, cssID, robots, noComments, source — those bypass DCA validation
     * or change identity/permissions of the record.
     */
    protected function allowedFields(): array
    {
        return [
            'headline', 'subheadline', 'teaser', 'date', 'time',
            'published', 'start', 'stop',
        ];
    }

    /**
     * @param int    $pid       News-Archive ID (tl_news_archive)
     * @param string $headline  Headline for the news entry
     * @param string|null $date Publication date in Y-m-d, defaults to today
     */
    public function create(int $pid, string $headline, ?string $date = null): string
    {
        // For create, the record doesn't exist yet — check archive access directly.
        $this->assertArchiveAccess($pid);

        $args = [
            '--headline' => $headline,
            '--pid'      => (string) $pid,
        ];
        if (null !== $date) {
            $args['--date'] = $date;
        }
        return $this->runCommand($this->createCommand, $args, 'news_create');
    }

    /**
     * @param int $id News entry ID
     * @param array<string, scalar|null> $fields Object mapping field name to new value, e.g. {"headline": "New title", "published": true, "teaser": "Lead text"}. Allowed field names: headline, subheadline, teaser, date, time, published, start, stop. Pass exactly the fields that should change.
     */
    public function update(int $id, array $fields): string
    {
        $this->assertRecordAccess($id, 'update');
        return $this->runCommand($this->updateCommand, [
            'id'    => (string) $id,
            '--set' => $this->buildSetOptions($fields),
        ], 'news_update');
    }

    public function delete(int $id): string
    {
        $this->assertRecordAccess($id, 'delete');
        return $this->runCommand($this->deleteCommand, [
            'id' => (string) $id,
        ], 'news_delete');
    }

    public function read(int $id): string
    {
        $this->assertRecordAccess($id, 'read');
        return $this->runCommand($this->readCommand, [
            'id' => (string) $id,
        ], 'news_read');
    }

    /**
     * Per-record permission: news belongs to a news archive (pid). Editor must
     * have backend access to that archive (BackendUser::hasAccess($pid, 'news')).
     */
    protected function assertRecordAccess(int $recordId, string $operation): void
    {
        $user = $this->requireBackendUser();
        if ($user->isAdmin) {
            return;
        }

        $this->framework->initialize();
        $news = NewsModel::findById($recordId);
        if (null === $news) {
            throw new ToolExecutionException(\sprintf('News-Eintrag %d nicht gefunden.', $recordId));
        }
        $this->assertArchiveAccess((int) $news->pid);
    }

    private function assertArchiveAccess(int $archiveId): void
    {
        $user = $this->requireBackendUser();
        if ($user->isAdmin) {
            return;
        }
        if (!$user->hasAccess($archiveId, 'news')) {
            throw new ToolAccessDeniedException(
                \sprintf('Kein Zugriff auf News-Archiv %d.', $archiveId)
            );
        }
    }
}
