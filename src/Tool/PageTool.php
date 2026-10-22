<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiCoreBundle\Command\PageCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PagePublishCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageUpdateCommand;

#[AsTool('page_create',  'Create a new Contao page (sub-page or root)', method: 'create')]
#[AsTool('page_update',  'Update one or more fields of a Contao page by ID', method: 'update')]
#[AsTool('page_delete',  'Delete a Contao page by ID, together with its subpages, articles and content elements: do not delete those first, one confirmation covers it all. Requires explicit confirmation.', method: 'delete')]
#[AsTool('page_read',    'Read a single page by ID', method: 'read')]
#[AsTool('page_publish', 'Publish or unpublish a Contao page by ID', method: 'publish')]
class PageTool extends AbstractCoreCommandTool
{
    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
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
            // `keywords` was listed until v0.16.4; tl_page has no such field in Contao 5.
            'title', 'pageTitle', 'language', 'description',
            'published', 'start', 'stop',
        ];
    }

    /**
     * @param string $title Page title
     * @param string $alias Page alias, empty for Contao's own
     * @param string $type  Page type, e.g. regular, root, forward
     * @param int    $pid   Parent page ID — the page goes to its end (0: top level, only for a root); leave 0 when after is given
     * @param int    $after ID of an existing page — the new one goes directly behind it, below the same parent; 0 to use pid
     */
    #[AiContract(
        writes: true, tables: ['tl_page'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id'],
    )]
    public function create(string $title, string $alias, string $type, int $pid = 0, int $after = 0): string
    {
        // Directly behind a page (v0.22.0, core v1.12.0): its parent, then the same voters.
        if ($after > 0 && 0 !== $pid) {
            throw new ToolRefusedException('after übernimmt die übergeordnete Seite dieser Seite; pid dann weglassen (0).');
        }
        if ($after > 0) {
            $pid = (int) $this->siblingForAfter($this->createCommand, 'tl_page', $after)['pid'];
        }

        // The voters decide (v0.18.0): edit and hierarchy rights on the parent, the page
        // type, a root only on top and nothing else there, error pages only below a root.
        // `sorting` must not be null — null is how the page voter recognises a copy. The
        // core picks the position; 0 is what DC_Table's own "new" button asks with.
        if (0 !== $pid && null === $this->dcAccess->fetchRecord('tl_page', $pid)) {
            throw new ToolRefusedException(\sprintf('Seite %d nicht gefunden.', $pid));
        }
        $this->dcAccess->assertCreate('tl_page', ['pid' => $pid, 'sorting' => 0, 'type' => $type, 'title' => $title, 'alias' => $alias]);

        $this->assertFieldsCreatable('tl_page', ['title' => $title, 'alias' => $alias, 'type' => $type]);

        // null = not passed: with --after the core takes the parent itself
        return $this->runCommand($this->createCommand, [
            '--title' => $title,
            '--alias' => $alias,
            '--type'  => $type,
            '--pid'   => $after > 0 ? null : (string) $pid,
            '--after' => $after > 0 ? (string) $after : null,
        ], 'page_create');
    }

    /**
     * @param int $id Page ID
     * @param array<string,scalar|null>|string $fields Field names and their new values, as a list of name and value (["published", "1"]) or as an object, e.g. {"title": "Neu", "description": "…"}. Allowed field names: title, pageTitle, language, description, published, start, stop. Pass exactly the fields that should change.
     */
    #[AiContract(
        writes: true, tables: ['tl_page'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: true, answerShape: ['status', 'id'],
    )]
    public function update(int $id, array|string $fields): string
    {
        $fields = self::payload($fields, 'fields');
        $this->dcAccess->assertUpdate('tl_page', $id, self::writtenValues($fields));
        return $this->runCommand($this->updateCommand, [
            'id'    => (string) $id,
            '--set' => $this->buildSetOptions($fields, 'tl_page'),
        ], 'page_update');
    }

    #[AiContract(
        writes: true, tables: ['tl_page'], trace: ['tl_undo', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id', 'deleted'],
    )]
    public function delete(int $id): string
    {
        $page = $this->dcAccess->assertDelete('tl_page', $id);

        $title = (string) ($page['title'] ?? 'unbekannt');
        $stagePayload = ['id' => $id, 'title' => $title, 'pid' => (int) $page['pid']];
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
        $this->dcAccess->assertRead('tl_page', $id);
        return $this->runCommand($this->readCommand, ['id' => (string) $id], 'page_read');
    }

    #[AiContract(
        writes: true, tables: ['tl_page'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: true, answerShape: ['status', 'id'],
    )]
    public function publish(int $id, bool $published): string
    {
        $this->dcAccess->assertUpdate('tl_page', $id, ['published' => $published ? '1' : '']);
        // In the back end this is the `published` toggle, which needs that field — checked
        // before the confirmation, so nobody confirms what is then refused (v0.17.0).
        $this->assertFieldsWritable('tl_page', ['published']);

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
}
