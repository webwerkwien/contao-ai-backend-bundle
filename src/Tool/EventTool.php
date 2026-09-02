<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\CalendarEventsModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;
use Webwerkwien\ContaoAiCoreBundle\Command\EventCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\EventDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\EventReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\EventUpdateCommand;

/**
 * CRUD for calendar events, modelled on {@see NewsTool}.
 *
 * The gap this closes was never a decision. CRUD tools shipped with v0.1.0 for
 * page, article, content and news; calendars and FAQs only ever arrived through
 * the cloner and the rewriter in phase 9. So the agent could *clone* a calendar
 * and *rewrite* an event, but not create or update one — a shape nobody chose.
 *
 * 🎯 Cheap to close because the expensive half already existed:
 * `RecordPermissionChecker` and `AbstractCoreCommandTool::TABLE_MODULE` have
 * covered `tl_calendar` and `tl_calendar_events` since the cloner was built, and
 * all sixteen console commands are in contao-ai-core-bundle. What was missing is
 * the wrapper.
 */
#[AsTool('event_create', 'Create a new event inside a Contao calendar', method: 'create')]
#[AsTool('event_update', 'Update one or more fields of an existing event by ID', method: 'update')]
#[AsTool('event_delete', 'Delete a Contao event by ID. Requires explicit confirmation.', method: 'delete')]
#[AsTool('event_read',   'Read a single event by ID and return all fields', method: 'read')]
class EventTool extends AbstractCoreCommandTool
{
    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
        private readonly ContaoFramework $framework,
        private readonly EventCreateCommand $createCommand,
        private readonly EventUpdateCommand $updateCommand,
        private readonly EventDeleteCommand $deleteCommand,
        private readonly EventReadCommand $readCommand,
    ) {
        parent::__construct($accessChecker, $tokenChecker);
    }

    public function getToolName(): string
    {
        return 'event_create';
    }

    /**
     * Allow-list of editable event fields, taken from the live DCA of
     * `tl_calendar_events` rather than from the news list.
     *
     * Excluded by design: id, tstamp, pid (use create), alias, author, cssClass,
     * source, jumpTo, articleId, url — identity, routing or permissions, none of
     * which an editing agent should reach. `recurring`/`repeatEach` are left out
     * as well: they are serialized structures, and a plain `--set` would write a
     * string into them.
     */
    protected function allowedFields(): array
    {
        return [
            'title', 'teaser', 'location', 'address',
            'startDate', 'endDate', 'addTime', 'startTime', 'endTime',
            'published', 'start', 'stop', 'featured',
        ];
    }

    /**
     * @param int         $pid       Calendar ID (tl_calendar)
     * @param string      $title     Title of the event
     * @param string|null $startDate Start date in Y-m-d; defaults to the command's own default
     */
    #[AiContract(
        writes: true, tables: ['tl_calendar_events'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id'],
    )]
    public function create(int $pid, string $title, ?string $startDate = null): string
    {
        // The record does not exist yet, so the calendar is what gets checked.
        $this->assertCalendarAccess($pid);

        // Keys stay inline on purpose — see runCommand(): a null is dropped
        // there, and that is what keeps this call visible to
        // ToolArgumentsMatchCommandTest.
        return $this->runCommand($this->createCommand, [
            '--title'     => $title,
            '--pid'       => (string) $pid,
            '--startDate' => $startDate,
        ], 'event_create');
    }

    /**
     * @param int $id Event ID
     * @param array<string, scalar|null> $fields Object mapping field name to new value, e.g. {"title": "Sommerfest", "published": true, "location": "Rathausplatz"}. Allowed field names: title, teaser, location, address, startDate, endDate, addTime, startTime, endTime, published, start, stop, featured. Pass exactly the fields that should change.
     */
    #[AiContract(
        writes: true, tables: ['tl_calendar_events'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: true, answerShape: ['status', 'id'],
    )]
    public function update(int $id, array $fields): string
    {
        $this->assertRecordAccess($id, 'update');

        return $this->runCommand($this->updateCommand, [
            'id'    => (string) $id,
            '--set' => $this->buildSetOptions($fields),
        ], 'event_update');
    }

    #[AiContract(
        writes: true, tables: ['tl_calendar_events'], trace: ['tl_undo', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id', 'deleted'],
    )]
    public function delete(int $id): string
    {
        $this->assertRecordAccess($id, 'delete');

        // Two-step confirmation, exactly as for news: the first call stages and
        // returns the question, the second one — after the user answered —
        // carries it out. See AbstractCoreCommandTool::requireConfirmation.
        $this->framework->initialize();
        $event = CalendarEventsModel::findById($id);
        $title = null !== $event && \is_string($event->title ?? null) ? (string) $event->title : 'unbekannt';

        $staged = $this->requireConfirmation(
            'event_delete',
            (string) $id,
            \sprintf('Der Termin "%s" (ID %d) soll endgültig gelöscht werden. Wirklich fortfahren?', $title, $id),
            [
                'id'       => $id,
                'calendar' => null !== $event ? (int) $event->pid : null,
                'title'    => $title,
            ],
        );
        if (null !== $staged) {
            return $staged;
        }

        return $this->runCommand($this->deleteCommand, ['id' => (string) $id], 'event_delete');
    }

    #[AiContract(writes: false, tables: ['tl_calendar_events'], trace: [])]
    public function read(int $id): string
    {
        $this->assertRecordAccess($id, 'read');

        return $this->runCommand($this->readCommand, ['id' => (string) $id], 'event_read');
    }

    /**
     * Per-record permission: an event belongs to a calendar (pid), and the editor
     * needs back end access to that calendar — the same rule Contao applies in
     * the calendar module.
     */
    protected function assertRecordAccess(int $recordId, string $operation): void
    {
        $user = $this->requireBackendUser();
        if ($user->isAdmin) {
            return;
        }

        $this->framework->initialize();
        $event = CalendarEventsModel::findById($recordId);
        if (null === $event) {
            throw new ToolRefusedException(\sprintf('Termin %d nicht gefunden.', $recordId));
        }

        $this->assertCalendarAccess((int) $event->pid);
    }

    private function assertCalendarAccess(int $calendarId): void
    {
        $user = $this->requireBackendUser();
        if ($user->isAdmin) {
            return;
        }
        if (!$this->authorizationChecker->isGranted('contao_user.calendars', [$calendarId])) {
            throw new ToolAccessDeniedException(
                \sprintf('Kein Zugriff auf Kalender %d.', $calendarId)
            );
        }
    }
}
