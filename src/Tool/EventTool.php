<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\CalendarEventsModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
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
        // The voters decide (v0.18.0): the calendar module and the right on the calendar.
        if (null === $this->dcAccess->fetchRecord('tl_calendar', $pid)) {
            throw new ToolRefusedException(\sprintf('Kalender %d nicht gefunden.', $pid));
        }
        $this->dcAccess->assertCreate('tl_calendar_events', ['pid' => $pid, 'title' => $title]);

        // Keys stay inline on purpose — see runCommand(): a null is dropped
        // there, and that is what keeps this call visible to
        // ToolArgumentsMatchCommandTest.
        $this->assertFieldsCreatable('tl_calendar_events', ['title' => $title, 'startDate' => $startDate]);

        return $this->runCommand($this->createCommand, [
            '--title'     => $title,
            '--pid'       => (string) $pid,
            '--startDate' => $startDate,
        ], 'event_create');
    }

    /**
     * @param int $id Event ID
     * @param array<string,scalar|null>|string $fields Field names and their new values, as a list of name and value (["published", "1"]) or as an object, e.g. {"title": "Sommerfest", "published": true, "location": "Rathausplatz"}. Allowed field names: title, teaser, location, address, startDate, endDate, addTime, startTime, endTime, published, start, stop, featured. A day as YYYY-MM-DD (startDate, endDate), a time as HH:MM (startTime, endTime; a time makes it an event with a time). Pass exactly the fields that should change.
     */
    #[AiContract(
        writes: true, tables: ['tl_calendar_events'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: true, answerShape: ['status', 'id'],
    )]
    public function update(int $id, array|string $fields): string
    {
        $fields = self::payload($fields, 'fields');
        $fields = $this->writeFields($fields, 'tl_calendar_events');
        $this->dcAccess->assertUpdate('tl_calendar_events', $id, self::writtenValues($fields));

        // A day as 2026-11-27 and a time as 10:00 go to the command's own options, which
        // turn them into the stored timestamps (and set addTime for a time). Through
        // --set the core refuses them: those columns hold Unix timestamps. Until v0.19.2
        // the chat could not move an event or give it a time.
        $times = [];
        foreach (self::TIME_OPTIONS as $field => $pattern) {
            $value = $fields[$field] ?? null;
            if (\is_string($value) && preg_match($pattern, trim($value))) {
                $times[$field] = trim($value);
                unset($fields[$field]);
            }
        }
        if ([] !== $times) {
            // The options arrived in core v0.28.0 (2026-09-19); an older core would end
            // in Symfony's "option does not exist" and a failure report.
            if (!$this->updateCommand->getDefinition()->hasOption('startDate')) {
                throw new ToolRefusedException('Datum und Uhrzeit eines Termins lassen sich im Chat erst mit contao-ai-core-bundle ab v0.28.0 ändern. Es wurde nichts geschrieben.');
            }
            // A time makes the core set addTime, so its right is needed as well — as it
            // is when addTime comes through --set.
            $rights = array_keys($times);
            if (isset($times['startTime']) || isset($times['endTime'])) {
                $rights[] = 'addTime';
            }
            $this->assertFieldsWritable('tl_calendar_events', $rights);
        }

        // A null option is not passed (runCommand()).
        return $this->runCommand($this->updateCommand, [
            'id'          => (string) $id,
            '--set'       => [] === $fields ? [] : $this->buildSetOptions($fields, 'tl_calendar_events'),
            '--startDate' => $times['startDate'] ?? null,
            '--endDate'   => $times['endDate'] ?? null,
            '--startTime' => $times['startTime'] ?? null,
            '--endTime'   => $times['endTime'] ?? null,
        ], 'event_update');
    }

    /** Field => the human form the command's option of the same name takes. */
    private const TIME_OPTIONS = [
        'startDate' => '/^\d{4}-\d{2}-\d{2}$/',
        'endDate'   => '/^\d{4}-\d{2}-\d{2}$/',
        'startTime' => '/^\d{1,2}:\d{2}$/',
        'endTime'   => '/^\d{1,2}:\d{2}$/',
    ];

    #[AiContract(
        writes: true, tables: ['tl_calendar_events'], trace: ['tl_undo', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id', 'deleted'],
    )]
    public function delete(int $id): string
    {
        $this->dcAccess->assertDelete('tl_calendar_events', $id);

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
        $this->dcAccess->assertRead('tl_calendar_events', $id);

        return $this->runCommand($this->readCommand, ['id' => (string) $id], 'event_read');
    }
}
