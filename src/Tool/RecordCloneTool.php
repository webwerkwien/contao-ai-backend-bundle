<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Doctrine\DBAL\Connection;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\AllowListGrant;
use Webwerkwien\ContaoAiBackendBundle\Security\RecordPermissionChecker;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordCloneCommand;

/**
 * record_clone: macro tool that clones a Contao container record and
 * its child cascade in one server-side operation. Avoids the N+1 tool-call
 * pattern (`news_archive_create` + N×`news_read` + N×`news_create`) that
 * blows the rate-limit and context window on a normal LLM-orchestrated run.
 *
 * It began with tl_news_archive (cascades to tl_news). Further entities
 * (tl_calendar → tl_calendar_events, tl_faq_category → tl_faq, tl_page →
 * tl_article → tl_content) came by registering additional
 * EntityClonerInterface implementations under the same tagged-iterator.
 *
 * Editor access: source voter via RecordPermissionChecker (the same patterns
 * as NewsTool/PageTool/..., only central) + container-creation voter
 * (newp/calp/faqp 'create', or USER_CAN_EDIT_PAGE_HIERARCHY on source.pid for
 * tl_page).
 */
#[AsTool(
    'record_clone',
    'Clone a Contao container record and its full child cascade in one server-side operation. '
    .'Returns the new root id and the count of cloned children. '
    .'Supported sourceTable values: tl_news_archive (cascades to tl_news), tl_calendar (cascades to tl_calendar_events), tl_faq_category (cascades to tl_faq), tl_page (cascades to tl_article and their tl_content elements; nested content children inside accordion/colset/grouped layouts are included automatically), tl_form (cascades to its form fields), tl_newsletter_channel (cascades to its newsletters, which come out unsent; recipients only with withRecipients=true), tl_module and tl_layout (a single record, the copy stays in the same theme). '
    .'News, events, FAQ and newsletters are optional Contao bundles: when one is not installed, its table is missing and the call says so. '
    .'Set recursive=true on tl_page to also clone the full descendant subpage tree (capped at depth 10 / 50 total pages); ignored for the other tables which have no subtree semantics. '
    .'Cloned pages, articles, news, events and FAQ come out unpublished (content elements keep their visibility) so the operator can review/translate before publishing. '
    .'Use this instead of issuing many individual create calls for bulk-copy workflows. '
    .'Editor permission rules: Contao decides as in the back end — the caller needs access to the source and the right to create a record of that table next to it (for tl_page: the hierarchy right on the parent; for tl_form, tl_newsletter_channel and the archives: the create right of the user group; for tl_module: the allowed module types; for tl_layout: the layout right). With recursive=true on tl_page every subpage in the source tree must be copyable — the call refuses up-front otherwise (no partial clones).',
    method: 'cloneRecord',
)]
class RecordCloneTool extends AbstractCoreCommandTool
{
    /**
     * Tables that may be passed as `sourceTable`. The actual cloning logic
     * lives in core-bundle EntityCloner services, registered conditionally
     * per Contao-plugin (NewsArchiveCloner only when contao/news-bundle is
     * installed). This list is the agent-facing input filter; mismatches with
     * the registry come back as a structured "no cloner registered" error
     * from RecordCloneCommand.
     *
     * (The general table-to-module mapping has lived in
     * AbstractCoreCommandTool::TABLE_MODULE since 2026-09-02 — see the note below
     * on why this is deliberately a separate, narrower list.)
     *
     * @var array<string, string> table => required backend module
     */
    /**
     * Container tables that can be cloned, and their back-end module.
     *
     * ⚠️ Deliberately NOT `AbstractCoreCommandTool::TABLE_MODULE`. Until 2026-09-02
     * this one had the same name, but it is a different concept: the base list maps
     * ALL tables that can be surveyed (10 entries, incl. tl_news, tl_content,
     * tl_files), this one only the four containers for which a clone is defined at
     * all. The values match, the purpose does not — hence renamed rather than merged.
     */
    private const CONTAINER_MODULE = [
        'tl_news_archive'       => 'news',
        'tl_calendar'           => 'calendar',
        'tl_faq_category'       => 'faq',
        'tl_page'               => 'page',
        // v0.19.0, core v1.8.0. Module and layout live in the theme manager.
        'tl_form'               => 'form',
        'tl_newsletter_channel' => 'newsletter',
        'tl_module'             => 'themes',
        'tl_layout'             => 'themes',
    ];

    /**
     * Auto-grant after creating a container. Maps the source table to the
     * tl_user allow-list field. After a successful clone the new container ID is
     * appended to the current non-admin user's list, so they do not lock
     * themselves out. tl_page deliberately not included: pagemounts has different
     * semantics (visibility in the tree rather than a simple allow-list), it would
     * need separate handling.
     *
     * @var array<string, string>
     */
    private const AUTO_GRANT_FIELD = [
        'tl_news_archive'       => 'news',
        'tl_calendar'           => 'calendars',
        'tl_faq_category'       => 'faqs',
        // The `userRoot` of their DCA, as for the three above. Module and layout have
        // no allow-list: the theme rights are not per record.
        'tl_form'               => 'forms',
        'tl_newsletter_channel' => 'newsletters',
    ];

    /**
     * Source table of the running cloneRecord call. postProcessDecoded() needs
     * it but does not get it from the decoded array.
     */
    private ?string $pendingSourceTable = null;

    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
        private readonly RecordCloneCommand $cloneCommand,
        private readonly RecordPermissionChecker $permissionChecker,
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
        parent::__construct($accessChecker, $tokenChecker);
    }

    public function getToolName(): string
    {
        return 'record_clone';
    }

    /**
     * Visible when the user has at least one source module mounted. The per-call
     * voter (assertSourceAccess + assertCanCreateContainer) then decides whether
     * the specific table/ID is allowed.
     */
    public function isAccessibleBy(BackendUser $user): bool
    {
        if ($user->isAdmin) {
            return true;
        }
        $userModules = (array) ($user->modules ?? []);
        foreach (self::CONTAINER_MODULE as $module) {
            if (\in_array($module, $userModules, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string                              $sourceTable    Container table to clone (tl_news_archive, tl_calendar, tl_faq_category, tl_page)
     * @param int                                 $sourceId       ID of the source container record
     * @param array<string,scalar|null>|string    $modifications  Field overrides for the cloned root record, e.g. {"title": "Pressemitteilungen 2026"}. Each table accepts a fixed set of fields: tl_page = title, pageTitle, description, published, hide, and for a root page also language, urlPrefix, urlSuffix, fallback, dns (each needs the user's field right, which editors rarely have); tl_faq_category = title, headline; tl_news_archive, tl_calendar, tl_form and tl_newsletter_channel = title; tl_module and tl_layout = name. Anything else is refused and listed back under "ignored_modifications" in the result — check that key rather than assuming an override was applied. Note that "alias" is never taken from here; it is always regenerated from the title. Cloned pages come out unpublished and a cloned root is never the main language (fallback) by itself; pass {"published": "1"} to publish the cloned root right away (its subpages stay unpublished). Before cloning a root into another language, ask the user which language is the main one — never assume. {"fallback": "1"} is refused while another root of that domain is the main language, and no chat tool changes `fallback` on an existing root: if the clone is to be the main language, the user first removes the language fallback flag from the existing root in the back end (website root settings), then you clone with {"fallback": "1", "published": "1"}. Accepts an object/array OR a JSON-encoded string — symfony/ai's JSON-schema view of `array` lets the model pick either.
     * @param bool                                $recursive      For container-of-container tables (currently only tl_page), also clone the entire descendant tree (subpages with their articles+content) under the new root. Capped at depth 10 / 50 total pages. Ignored for tables without subtree semantics. Default: false (only direct cascade).
     * @param bool                                $withRecipients tl_newsletter_channel only: also copy the channel's recipients. Default false — the clone then has none. The recipients consented to the source channel, not to the copy, so ask the user whether the clone should take them along; never decide this yourself. With true the server asks the user once more before it clones.
     */
    #[AiContract(
        // The caller picks the table — claim nothing that is not fixed here.
        writes: true, trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: true, answerShape: ['status', 'id', 'count'],
    )]
    public function cloneRecord(string $sourceTable, int $sourceId, array|string $modifications = [], bool $recursive = false, bool $withRecipients = false): string
    {
        return $this->cloneChecked($sourceTable, $sourceId, $modifications, $recursive, $withRecipients, false);
    }

    /**
     * The CLI bridge (`bridge clone`). It has no chat turn in which the server could ask,
     * so the CLI asks before it sends withRecipients — at the terminal, or `--yes` without
     * one (v0.19.0). Not a tool: the model reaches only cloneRecord().
     *
     * @param array<string, scalar|null>|string $modifications
     */
    public function cloneFromBridge(string $sourceTable, int $sourceId, array|string $modifications = [], bool $recursive = false, bool $withRecipients = false): string
    {
        return $this->cloneChecked($sourceTable, $sourceId, $modifications, $recursive, $withRecipients, true);
    }

    /**
     * @param array<string, scalar|null>|string $modifications
     */
    private function cloneChecked(string $sourceTable, int $sourceId, array|string $modifications, bool $recursive, bool $withRecipients, bool $recipientsConfirmed): string
    {
        $user = $this->requireBackendUser();

        if (!isset(self::CONTAINER_MODULE[$sourceTable])) {
            throw new ToolAccessDeniedException(
                \sprintf('Tabelle "%s" wird vom record_clone-Tool nicht unterstützt.', $sourceTable)
            );
        }

        // The source as DC_Table's copy judges it: read access to an archive, form,
        // channel, module or layout; for a page the copy question itself (edit or
        // hierarchy right on it, recordCopyDenialReason()). Then the right to create
        // the new container next to it. Until v0.19.2 the source needed the edit right,
        // although this comment already said "read": an editor allowed to create
        // archives but not to change them could not clone one.
        if ('tl_page' === $sourceTable) {
            $reason = $this->permissionChecker->recordCopyDenialReason($user, 'tl_page', $sourceId);
            if (null !== $reason) {
                throw new ToolAccessDeniedException($reason);
            }
        } else {
            $this->permissionChecker->assertRecordAccess($user, $sourceTable, $sourceId, 'read');
        }
        $this->permissionChecker->assertCanCreateContainer($user, $sourceTable, $sourceId);

        // With tl_page recursive=true the editor needs the edit right on every
        // subpage in the source tree, because the core cloner currently accepts
        // no skip list (pre-flight instead of skip-with-note). We show the
        // refused IDs explicitly, so the model or the operator can adjust the
        // scope before trying again. Cap = depth 10 / 50 pages — identical to the
        // core cloner.
        if ($recursive && 'tl_page' === $sourceTable) {
            $this->assertSubtreeAccess($user, $sourceId);
        }

        if ($withRecipients) {
            // Refused rather than ignored, as in the core: whoever asks for recipients
            // expects a copy of people, and silence would read as "done".
            if ('tl_newsletter_channel' !== $sourceTable) {
                throw new ToolRefusedException('withRecipients gilt nur für tl_newsletter_channel.');
            }
            if (!$this->cloneCommand->getDefinition()->hasOption('with-recipients')) {
                throw new ToolRefusedException('Abonnenten mitklonen braucht contao-ai-core-bundle ab v1.8.0; installiert ist eine ältere Version.');
            }
        }

        // Claude sometimes wraps the `array` payload into a JSON string instead
        // of sending it as a real object — observed live 2026-05-03 with
        // `argument #3 must be of type array, string given`. Decode first,
        // then run the same normalize pass other tools use.
        if (\is_string($modifications)) {
            $decoded = json_decode($modifications, true);
            $modifications = \is_array($decoded) ? $decoded : [];
        }
        $modifications = self::normalizeFieldsPayload($modifications);

        // An override is a value the clone gets instead of its source's — a write, and
        // like any write it needs the field right (v0.17.0). Until then {"published": "1"}
        // published a clone for an editor whose group grants no `tl_page::published`.
        // Not the create rule: a clone copies its source, it is no record with defaults,
        // so {"hide": ""} would show a hidden page in the navigation.
        // The core unpublishes a cloned page by itself; nobody needs to
        // pass {"published": ""}.
        //
        // Every key, null included: the core writes a null override too — as '' or, for
        // a flag, '0' — so {"hide": null} showed a hidden page once nulls were skipped
        // here. Unlike buildSetOptions(), nothing drops them
        // on the way to the command.
        $this->assertFieldsWritable($sourceTable, array_map('strval', array_keys($modifications)));

        // Last, once every check has passed: the question must not stage a clone that
        // would be refused anyway. The key holds the source, so a yes covers this
        // channel only.
        if ($withRecipients && !$recipientsConfirmed) {
            $staged = $this->askBeforeCopyingRecipients($sourceId);
            if (null !== $staged) {
                return $staged;
            }
        }

        $modsJson = json_encode($modifications, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (false === $modsJson) {
            $modsJson = '{}';
        }

        $args = [
            '--source-table'  => $sourceTable,
            '--source-id'     => (string) $sourceId,
            '--modifications' => $modsJson,
            // Inline, null for absent: ToolArgumentsMatchCommandTest reads this literal.
            '--with-recipients' => $withRecipients ? true : null,
        ];
        if ($recursive) {
            $args['--recursive'] = true;
        }
        $this->pendingSourceTable = $sourceTable;
        try {
            return $this->runCommand($this->cloneCommand, $args, 'record_clone');
        } finally {
            $this->pendingSourceTable = null;
        }
    }

    /**
     * The recipients consented to the source channel; a copy carries that consent to a
     * channel they never chose. So the user says yes in a later message, held by the
     * server (requireConfirmation), not by the model.
     */
    private function askBeforeCopyingRecipients(int $channelId): ?string
    {
        $title = (string) $this->connection->fetchOne('SELECT title FROM tl_newsletter_channel WHERE id = ?', [$channelId]);
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_newsletter_recipients WHERE pid = ?', [$channelId]);

        return $this->requireConfirmation(
            'record_clone',
            'recipients:' . $channelId,
            \sprintf(
                'Der Newsletter-Kanal "%s" (ID %d) soll samt seiner %d Abonnenten geklont werden. Die Abonnenten haben dem bestehenden Kanal zugestimmt, nicht dem neuen. Wirklich mit Abonnenten klonen?',
                $title, $channelId, $count,
            ),
            ['sourceTable' => 'tl_newsletter_channel', 'sourceId' => $channelId, 'title' => $title, 'recipients' => $count],
        );
    }

    /**
     * After a successful clone, add the new container ID to the current non-admin
     * user's allow-list — otherwise they have just created something they may no
     * longer see themselves. Stock Contao does this in the UserRootListener's
     * `oncopy_callback`, which does not apply here: it requires the ID in the
     * back-end session's `new_records`.
     *
     * Done in the post-process hook, so the wrapper in runCommand does not take
     * away our decoded access to the result.
     */
    protected function postProcessDecoded(array &$decoded, string $toolName): void
    {
        if ('record_clone' !== $toolName || null === $this->pendingSourceTable) {
            return;
        }
        $field = self::AUTO_GRANT_FIELD[$this->pendingSourceTable] ?? null;
        if (null === $field) {
            return; // tl_page → no auto-grant
        }
        $newId = (int) ($decoded['id'] ?? 0);
        if (0 === $newId) {
            return;
        }
        $user = $this->getCurrentBackendUser();
        if (null === $user) {
            return;
        }

        // As Contao's UserRootListener::adjustPermissions() does after a copy in the back
        // end (v0.19.0, AllowListGrant). Until then only the user's own list got the id —
        // useless for a user who inherits only from the group (`inherit = group`).
        // Visible in the tool result, so the model can tell the user.
        $granted = AllowListGrant::grant($this->connection, $user, $field, $newId);
        if (null !== $granted) {
            $decoded['auto_granted'] = $granted;
        }
    }

    /**
     * BFS over subpages (same cap as the core cloner: depth 10 / 50 nodes), per
     * node the copy question to Contao's voters (since v0.18.0, for admins too;
     * until then USER_CAN_EDIT_PAGE). A single refusal aborts hard with a list of
     * the first N refused IDs — the editor sees what is missing instead of finding
     * a "Swiss cheese" structure after the clone.
     */
    private function assertSubtreeAccess(BackendUser $user, int $rootId): void
    {
        $this->framework->initialize();

        $maxDepth = 10;
        $maxNodes = 50;
        $visited  = [];
        $queue    = [[$rootId, 0]];
        $refused  = [];

        while ([] !== $queue && \count($visited) < $maxNodes) {
            [$pageId, $depth] = array_shift($queue);
            if (isset($visited[$pageId])) {
                continue;
            }
            $visited[$pageId] = true;

            // The clipboard question of DC_Table (v0.18.0): may this page be copied.
            $reason = $this->permissionChecker->recordCopyDenialReason($user, 'tl_page', $pageId);
            if (null !== $reason) {
                $refused[] = ['id' => $pageId, 'reason' => $reason];
                if (\count($refused) >= 10) {
                    break;
                }
                continue;
            }

            if ($depth >= $maxDepth) {
                continue;
            }
            $childIds = $this->connection->fetchFirstColumn(
                'SELECT id FROM tl_page WHERE pid = ? ORDER BY sorting ASC',
                [$pageId]
            );
            foreach ($childIds as $childId) {
                $queue[] = [(int) $childId, $depth + 1];
            }
        }

        if ([] !== $refused) {
            $list = implode(', ', array_map(
                static fn(array $r): string => \sprintf('%d (%s)', $r['id'], $r['reason']),
                $refused
            ));
            throw new ToolAccessDeniedException(\sprintf(
                'Recursive Clone abgebrochen — kein Recht, folgende Unterseiten zu kopieren: %s. '
                .'Nutze recursive=false oder bitte einen Admin um Zugriff.',
                $list
            ));
        }
    }
}
