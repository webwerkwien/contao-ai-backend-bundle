<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleUpdateCommand;

#[AsTool('article_create', 'Create a Contao article: at the end of a page (pid), or directly behind an existing article (after)', method: 'create')]
#[AsTool('article_update', 'Update one or more fields of a Contao article by ID', method: 'update')]
#[AsTool('article_delete', 'Delete a Contao article by ID, together with its content elements: do not delete them one by one first, one confirmation covers it all. Requires explicit confirmation.', method: 'delete')]
#[AsTool('article_read',   'Read a single article by ID', method: 'read')]
class ArticleTool extends AbstractCoreCommandTool
{
    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
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
            // `keywords` was listed until v0.16.4; tl_article has no such field in Contao 5.
            'title', 'teaser', 'showTeaser',
            'published', 'start', 'stop',
        ];
    }

    /**
     * @param string $title    Article title
     * @param int    $pid      Page ID — the article goes to its end; 0 when after is given
     * @param string $inColumn Layout column; empty: main, or with after the column of that article
     * @param int    $after    ID of an existing article — the new one goes directly behind it, on the same page; 0 to use pid. Give exactly one of pid and after
     */
    #[AiContract(
        writes: true, tables: ['tl_article'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id'],
    )]
    public function create(string $title, int $pid = 0, string $inColumn = '', int $after = 0): string
    {
        // Directly behind an article (v0.22.0, core v1.12.0): its page, then the same voters.
        if (($pid > 0) === ($after > 0)) {
            throw new ToolRefusedException('Entweder pid (am Ende der Seite) oder after (direkt hinter diesem Artikel) angeben, genau eines davon.');
        }
        // The column the core writes: the one given, else the sibling's (core v1.12.0), else
        // main — and the voters judge that one (review v0.22.0: 'main' was always sent and
        // overrode the sibling's column).
        $given = $inColumn;
        if ($after > 0) {
            $sibling = $this->siblingForAfter($this->createCommand, 'tl_article', $after);
            $pid = (int) $sibling['pid'];
            $inColumn = '' !== $given ? $given : (string) ($sibling['inColumn'] ?? 'main');
        }
        $inColumn = '' !== $inColumn ? $inColumn : 'main';

        // The voters decide (v0.18.0): edit and hierarchy rights for articles on the page,
        // its type, and a layout with a column for articles. `sorting` non-null, as in
        // PageTool::create().
        if (null === $this->dcAccess->fetchRecord('tl_page', $pid)) {
            throw new ToolRefusedException(\sprintf('Übergeordnete Seite %d nicht gefunden.', $pid));
        }
        $this->dcAccess->assertCreate('tl_article', ['pid' => $pid, 'sorting' => 0, 'inColumn' => $inColumn, 'title' => $title]);

        $this->assertFieldsCreatable('tl_article', ['title' => $title, 'inColumn' => $inColumn]);

        // null = not passed: with --after the core takes the page itself
        return $this->runCommand($this->createCommand, [
            '--title'    => $title,
            '--pid'      => $after > 0 ? null : (string) $pid,
            '--after'    => $after > 0 ? (string) $after : null,
            // with after and no column given, the core takes the sibling's
            '--inColumn' => $after > 0 && '' === $given ? null : $inColumn,
        ], 'article_create');
    }

    /**
     * @param int $id Article ID
     * @param array<string,scalar|null>|string $fields Field names and their new values, as a list of name and value (["published", "1"]) or as an object, e.g. {"title": "Neu", "published": "1"}. Allowed field names: title, teaser, showTeaser, published, start, stop. Pass exactly the fields that should change.
     */
    #[AiContract(
        writes: true, tables: ['tl_article'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: true, answerShape: ['status', 'id'],
    )]
    public function update(int $id, array|string $fields): string
    {
        $fields = self::payload($fields, 'fields');
        $fields  = $this->writeFields($fields, 'tl_article');
        $article = $this->dcAccess->assertUpdate('tl_article', $id, self::writtenValues($fields));
        // Field rights first, so nobody confirms what is then refused (as page_publish).
        $set = $this->buildSetOptions($fields, 'tl_article');

        // Taking a live article offline removes its content from every visitor at
        // once, like unpublishing a page, which has asked since 2026-09-02. The
        // article did not (decided 2026-10-19, v0.19.3).
        $published = $fields['published'] ?? null;
        // "false" as text too, as the core reads it (EventTimes::flag); false itself casts to ''
        if (null !== $published && \in_array(strtolower(trim((string) $published)), ['', '0', 'false'], true) && !empty($article['published'])) {
            $staged = $this->requireConfirmation(
                'article_update',
                'unpublish:' . $id,
                \sprintf('Den Artikel "%s" (ID %d) wirklich offline nehmen? Sein Inhalt ist danach für Besucher nicht mehr sichtbar.', (string) ($article['title'] ?? ''), $id),
                ['id' => $id, 'action' => 'unpublish'],
            );

            if (null !== $staged) {
                return $staged;
            }
        }

        return $this->runCommand($this->updateCommand, [
            'id'    => (string) $id,
            '--set' => $set,
        ], 'article_update');
    }

    #[AiContract(
        writes: true, tables: ['tl_article'], trace: ['tl_undo', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id', 'deleted'],
    )]
    public function delete(int $id): string
    {
        $article = $this->dcAccess->assertDelete('tl_article', $id);

        $title = (string) ($article['title'] ?? 'unbekannt');
        $stagePayload = ['id' => $id, 'title' => $title, 'pid' => (int) $article['pid']];
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

    #[AiContract(writes: false, tables: ['tl_article'], trace: [])]
    public function read(int $id): string
    {
        $this->dcAccess->assertRead('tl_article', $id);
        return $this->runCommand($this->readCommand, ['id' => (string) $id], 'article_read');
    }
}
