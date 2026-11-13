<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\RecordPermissionChecker;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\ConsoleRunner;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;

/**
 * Duplicating content elements and articles in the chat (v1.2.0): the back end's copy
 * and paste, run through core-bundle v1.17.0's `contao:content:copy` and
 * `contao:article:copy`.
 *
 * DC_Table asks two questions and no more, and so does this tool: may the user copy the
 * source — the clipboard's `CreateAction` with the record — and may they create the copy
 * at the target. The field rights are not asked: a copy keeps every field of its source,
 * including fields the user may not edit (an element's `cssID`, say), as in the back end,
 * whose edit form after the copy is where the field rights apply. In the chat that is
 * `content_update` and `article_update`.
 *
 * The commands run by name, as this bundle allows cores without them; the tools are
 * offered only where the core has them.
 */
#[AsTool(
    'content_copy',
    'Duplicate a content element with its nested elements, as copy and paste in the back end: by default directly behind the source; '
    .'after puts the copy directly behind another element, pid (with ptable) at the end of another parent. The copy takes every field of '
    .'the source, including fields the user may not edit, and keeps its visibility; its headline is marked as a copy. Change fields '
    .'afterwards with content_update. Use this rather than content_create with values read from the source.',
    method: 'copyContent',
)]
#[AsTool(
    'article_copy',
    'Duplicate an article with its content elements, as copy and paste in the back end: by default directly behind the source; after '
    .'puts the copy directly behind another article, pid at the end of another page. The copy takes every field and element of the '
    .'source; as in the back end it is unpublished, has no alias yet and belongs to the user, its title marked as a copy. Change it '
    .'afterwards with article_update.',
    method: 'copyArticle',
)]
class CopyTool extends AbstractCoreCommandTool
{
    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
        private readonly ConsoleRunner $console,
        private readonly RecordPermissionChecker $records,
    ) {
        parent::__construct($accessChecker, $tokenChecker);
    }

    public function getToolName(): string
    {
        return 'content_copy';
    }

    /**
     * Only where the core has the commands (v1.17.0); both arrived together.
     */
    public function isAccessibleBy(BackendUser $user): bool
    {
        return $this->console->has('contao:content:copy') && parent::isAccessibleBy($user);
    }

    /**
     * @param int    $id     ID of the content element to copy
     * @param int    $pid    ID of another parent (usually an article) — the copy goes to its end; 0 to copy directly behind the source or behind after
     * @param string $ptable Parent table of pid (tl_article, tl_news, tl_content, …); empty: the source's, required when the source lies inside another element. Only with pid
     * @param int    $after  ID of an element — the copy goes directly behind it, below the same parent; 0 to copy directly behind the source or into pid. Not together with pid
     */
    #[AiContract(
        writes: true, tables: ['tl_content'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id'],
    )]
    public function copyContent(int $id, int $pid = 0, string $ptable = '', int $after = 0): string
    {
        $this->assertAccess('content_copy');
        $user = $this->requireBackendUser();

        if ($pid > 0 && $after > 0) {
            throw new ToolRefusedException('Entweder pid (ans Ende dieses Elternelements) oder after (direkt hinter dieses Element) angeben, nicht beides. Ohne beides kommt die Kopie direkt hinter das Original.');
        }
        if ('' !== $ptable && $pid <= 0) {
            throw new ToolRefusedException('ptable gehört zu pid; ohne pid bestimmt das Original oder after die Elterntabelle.');
        }

        $source = $this->dcAccess->fetchRecord('tl_content', $id);
        if (null === $source) {
            throw new ToolRefusedException(\sprintf('Inhaltselement %d nicht gefunden.', $id));
        }
        $this->assertVotedParent($source);
        $this->assertCopyable($user, 'tl_content', $id);

        // A nested source would otherwise take "tl_content" for pid, and an article ID
        // meant as the target would name a content element.
        if ($pid > 0 && '' === $ptable && 'tl_content' === ($source['ptable'] ?? null)) {
            throw new ToolRefusedException(\sprintf('Inhaltselement %d liegt in einem anderen Element. Zu pid bitte ptable angeben: tl_article für einen Artikel, tl_content für ein Element.', $id));
        }

        // Where the copy goes: behind another element, at the end of a parent, or by
        // default directly behind the source. An empty ptable is Contao 4 data, an article.
        if ($after > 0) {
            $sibling = $this->readableSibling('tl_content', $after);
            $target = ['pid' => (int) $sibling['pid'], 'ptable' => (string) ($sibling['ptable'] ?? '') ?: 'tl_article'];
        } elseif ($pid > 0) {
            $target = ['pid' => $pid, 'ptable' => '' !== $ptable ? $ptable : ((string) ($source['ptable'] ?? '') ?: 'tl_article')];
        } else {
            $target = ['pid' => (int) $source['pid'], 'ptable' => (string) ($source['ptable'] ?? '') ?: 'tl_article'];
        }

        // The target as for content_create: a missing parent element is "not found" for
        // everyone, then the editor's parent rule, then the parent itself.
        if ('tl_content' === $target['ptable'] && null === $this->dcAccess->fetchRecord('tl_content', $target['pid'])) {
            throw new ToolRefusedException(\sprintf('Eltern-Inhaltselement %d nicht gefunden.', $target['pid']));
        }
        $this->assertVotedParent($target);
        if (null === $this->dcAccess->fetchRecord($target['ptable'], $target['pid'])) {
            throw new ToolRefusedException(\sprintf('Übergeordneter Datensatz %d in "%s" nicht gefunden.', $target['pid'], $target['ptable']));
        }
        $this->dcAccess->assertCreate('tl_content', self::asCopy($source, $target));

        [$code, $output] = $this->console->run(self::given([
            'command'    => 'contao:content:copy',
            'id'         => (string) $id,
            '--to'       => $pid > 0 ? (string) $pid : null,
            '--ptable'   => $pid > 0 ? $target['ptable'] : null,
            '--after'    => $after > 0 ? (string) $after : null,
            '--operator' => (string) ($user->username ?? ''),
        ]));

        return $this->answer($code, $output, 'content_copy');
    }

    /**
     * @param int $id    ID of the article to copy
     * @param int $pid   ID of another page — the copy goes behind its last article; 0 to copy directly behind the source or behind after
     * @param int $after ID of an article — the copy goes directly behind it, on the same page; 0 to copy directly behind the source or onto pid. Not together with pid
     */
    #[AiContract(
        writes: true, tables: ['tl_article'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id'],
    )]
    public function copyArticle(int $id, int $pid = 0, int $after = 0): string
    {
        $this->assertAccess('article_copy');
        $user = $this->requireBackendUser();

        if ($pid > 0 && $after > 0) {
            throw new ToolRefusedException('Entweder pid (ans Ende dieser Seite) oder after (direkt hinter diesen Artikel) angeben, nicht beides. Ohne beides kommt die Kopie direkt hinter das Original.');
        }

        $source = $this->dcAccess->fetchRecord('tl_article', $id);
        if (null === $source) {
            throw new ToolRefusedException(\sprintf('Artikel %d nicht gefunden.', $id));
        }
        $this->assertCopyable($user, 'tl_article', $id);

        if ($after > 0) {
            $page = (int) $this->readableSibling('tl_article', $after)['pid'];
        } else {
            $page = $pid > 0 ? $pid : (int) $source['pid'];
        }

        if (null === $this->dcAccess->fetchRecord('tl_page', $page)) {
            throw new ToolRefusedException(\sprintf('Seite %d nicht gefunden.', $page));
        }

        // The copy as DC_Table hands it to the voters: tl_article resets `published`,
        // `alias` and `author` (doNotCopy), the author to its default, the acting user.
        // So the voters do not judge a published article of someone else that the user
        // would never create.
        $this->dcAccess->assertCreate('tl_article', array_replace(
            self::asCopy($source, ['pid' => $page]),
            ['published' => 0, 'alias' => '', 'author' => (int) ($user->id ?? 0)],
        ));

        [$code, $output] = $this->console->run(self::given([
            'command'    => 'contao:article:copy',
            'id'         => (string) $id,
            '--to'       => $pid > 0 ? (string) $pid : null,
            '--after'    => $after > 0 ? (string) $after : null,
            '--operator' => (string) ($user->username ?? ''),
        ]));

        return $this->answer($code, $output, 'article_copy');
    }

    /**
     * The clipboard's question: may the user copy this record.
     */
    private function assertCopyable(BackendUser $user, string $table, int $id): void
    {
        $denial = $this->records->recordCopyDenialReason($user, $table, $id);
        if (null !== $denial) {
            throw new ToolAccessDeniedException($denial);
        }
    }

    /**
     * The record the copy goes behind (`after`): it must exist and be readable, as for
     * content_create.
     *
     * @return array<string, mixed>
     */
    private function readableSibling(string $table, int $after): array
    {
        $sibling = $this->dcAccess->fetchRecord($table, $after);
        if (null === $sibling) {
            throw new ToolRefusedException(\sprintf('Datensatz %d in "%s" nicht gefunden — hinter ihn kann nichts kopiert werden.', $after, $table));
        }
        if (!$this->dcAccess->isGranted(new ReadAction($table, $sibling))) {
            throw new ToolAccessDeniedException(\sprintf('Kein Lesezugriff auf %s:%d.', $table, $after));
        }

        return $sibling;
    }

    /**
     * The new record the voters judge, as DC_Table's paste hands it to them: the source's
     * fields at the target, without its id, `sorting` and `tstamp` not yet set.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     *
     * @return array<string, mixed>
     */
    private static function asCopy(array $source, array $target): array
    {
        unset($source['id']);

        return array_replace($source, $target, ['sorting' => 0, 'tstamp' => 0]);
    }

    /**
     * The console input without the options not given: a null would pass the option
     * without a value.
     *
     * @param array<string, string|null> $input
     *
     * @return array<string, string>
     */
    private static function given(array $input): array
    {
        return array_filter($input, static fn (?string $value): bool => null !== $value);
    }
}
