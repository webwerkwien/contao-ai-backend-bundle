<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
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
            // `published` was listed until v0.16.4; a content element's visibility is
            // `invisible` in Contao 5, so the write always failed. Removed then without
            // a replacement, so the chat could not hide or show an element at all —
            // the eye icon in the back end (showcase, 2026-10-19). Back in v0.19.2.
            'headline', 'text', 'html', 'addImage',
            'invisible', 'start', 'stop',
        ];
    }

    #[AiContract(
        writes: true, tables: ['tl_content'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id'],
    )]
    public function create(string $type, int $pid, string $ptable = 'tl_article'): string
    {
        // The voters decide (v0.18.0): the rights on the article, news or event above,
        // the element type, and whether the parent element takes nested elements.
        // A missing parent element is "not found" for everyone, as elsewhere — not a
        // denial for editors only (Fable review 2026-10-14). Other parent tables are
        // judged first, so an editor's foreign table is never looked up.
        if ('tl_content' === $ptable && null === $this->dcAccess->fetchRecord($ptable, $pid)) {
            throw new ToolRefusedException(\sprintf('Eltern-Inhaltselement %d nicht gefunden.', $pid));
        }
        $this->assertVotedParent(['ptable' => $ptable, 'pid' => $pid]);
        if (null === $this->dcAccess->fetchRecord($ptable, $pid)) {
            throw new ToolRefusedException(\sprintf('Übergeordneter Datensatz %d in "%s" nicht gefunden.', $pid, $ptable));
        }
        $this->dcAccess->assertCreate('tl_content', ['pid' => $pid, 'ptable' => $ptable, 'sorting' => 0, 'type' => $type]);

        $this->assertFieldsCreatable('tl_content', ['type' => $type]);

        return $this->runCommand($this->createCommand, [
            '--type'   => $type,
            '--pid'    => (string) $pid,
            '--ptable' => $ptable,
        ], 'content_create');
    }

    /**
     * @param int $id Content element ID
     * @param array<string,scalar|null> $fields Field names and their new values, e.g. ["text", "<p>Neu</p>", "invisible", "1"]. Allowed field names: headline, text, html, addImage, invisible, start, stop. headline as plain text keeps its level (h1–h6); to set the level, send headline as JSON {"unit":"h2","value":"Titel"}, or headline_unit alone to change only the level. invisible "1" hides the element, "" shows it again (the eye icon in the back end). Pass exactly the fields that should change.
     */
    #[AiContract(
        writes: true, tables: ['tl_content'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: true, answerShape: ['status', 'id'],
    )]
    public function update(int $id, array $fields): string
    {
        // Before the voters see the values: a level sent on its own is the headline's.
        $fields = $this->writeFields($fields, 'tl_content');
        $this->assertVotedParent($this->dcAccess->assertUpdate('tl_content', $id, self::writtenValues($fields)));
        return $this->runCommand($this->updateCommand, [
            'id'    => (string) $id,
            '--set' => $this->buildSetOptions($fields, 'tl_content'),
        ], 'content_update');
    }

    #[AiContract(
        writes: true, tables: ['tl_content'], trace: ['tl_undo', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id', 'deleted'],
    )]
    public function delete(int $id): string
    {
        $element = $this->dcAccess->assertDelete('tl_content', $id);
        $this->assertVotedParent($element);

        $type = (string) ($element['type'] ?? 'unbekannt');
        $stagePayload = ['id' => $id, 'type' => $type, 'pid' => (int) $element['pid']];
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
        $this->assertVotedParent($this->dcAccess->assertRead('tl_content', $id));
        return $this->runCommand($this->readCommand, ['id' => (string) $id], 'content_read');
    }

    /**
     * Editors only below an article, news or event — the parents Contao's content voters
     * judge (see DataContainerAccess::contentParentDenial()). Admins keep every one.
     *
     * @param array<string, mixed> $record at least `ptable` and `pid`
     */
    private function assertVotedParent(array $record): void
    {
        if ($this->requireBackendUser()->isAdmin) {
            return;
        }

        $denial = $this->dcAccess->contentParentDenial($record);
        if (null !== $denial) {
            throw new ToolAccessDeniedException($denial);
        }
    }
}
