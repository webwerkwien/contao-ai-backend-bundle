<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\NewsModel;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
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
    #[AiContract(
        writes: true, tables: ['tl_news'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id'],
    )]
    public function create(int $pid, string $headline, ?string $date = null): string
    {
        // The voters decide (v0.18.0): the news module and the right on the archive.
        if (null === $this->dcAccess->fetchRecord('tl_news_archive', $pid)) {
            throw new ToolRefusedException(\sprintf('News-Archiv %d nicht gefunden.', $pid));
        }
        $this->dcAccess->assertCreate('tl_news', ['pid' => $pid, 'headline' => $headline]);

        // 🔴 Inline since 2026-09-02. The variable form was invisible to
        // ToolArgumentsMatchCommandTest, so `news_create` had never been checked
        // against `NewsCreateCommand` — not a defect in itself, but the same
        // blind spot that let `page_publish` ship broken. A null is dropped in
        // runCommand().
        // NewsCreateCommand writes --date into both `date` and `time`.
        $this->assertFieldsCreatable('tl_news', ['headline' => $headline, 'date' => $date, 'time' => $date]);

        return $this->runCommand($this->createCommand, [
            '--headline' => $headline,
            '--pid'      => (string) $pid,
            '--date'     => $date,
        ], 'news_create');
    }

    /**
     * @param int $id News entry ID
     * @param array<string,scalar|null>|string $fields Field names and their new values, as a list of name and value (["published", "1"]) or as an object, e.g. {"headline": "New title", "published": true, "teaser": "Lead text"}. Allowed field names: headline, subheadline, teaser, date, time, published, start, stop. Pass exactly the fields that should change.
     */
    #[AiContract(
        writes: true, tables: ['tl_news'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: true, answerShape: ['status', 'id'],
    )]
    public function update(int $id, array|string $fields): string
    {
        $fields = self::payload($fields, 'fields');
        $this->dcAccess->assertUpdate('tl_news', $id, self::writtenValues($fields));
        return $this->runCommand($this->updateCommand, [
            'id'    => (string) $id,
            '--set' => $this->buildSetOptions($fields, 'tl_news'),
        ], 'news_update');
    }

    #[AiContract(
        writes: true, tables: ['tl_news'], trace: ['tl_undo', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id', 'deleted'],
    )]
    public function delete(int $id): string
    {
        $this->dcAccess->assertDelete('tl_news', $id);

        // Two-step confirmation. First call stages, returns a question for
        // the user; second call within 5 minutes (after the user said yes)
        // proceeds to actually delete. See AbstractCoreCommandTool::requireConfirmation.
        $this->framework->initialize();
        $news = NewsModel::findById($id);
        $title = 'unbekannt';
        if (null !== $news && \is_string($news->headline ?? null)) {
            $decoded = @unserialize($news->headline, ['allowed_classes' => false]);
            if (\is_array($decoded) && isset($decoded['value'])) {
                $title = (string) $decoded['value'];
            } else {
                $title = (string) $news->headline;
            }
        }
        $stagePayload = [
            'id'      => $id,
            'archive' => null !== $news ? (int) $news->pid : null,
            'title'   => $title,
        ];
        $staged = $this->requireConfirmation(
            'news_delete',
            (string) $id,
            \sprintf('Die News "%s" (ID %d) soll endgültig gelöscht werden. Wirklich fortfahren?', $title, $id),
            $stagePayload,
        );
        if (null !== $staged) {
            return $staged;
        }

        return $this->runCommand($this->deleteCommand, [
            'id' => (string) $id,
        ], 'news_delete');
    }

    #[AiContract(writes: false, tables: ['tl_news'], trace: [])]
    public function read(int $id): string
    {
        $this->dcAccess->assertRead('tl_news', $id);
        return $this->runCommand($this->readCommand, [
            'id' => (string) $id,
        ], 'news_read');
    }
}
