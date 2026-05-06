<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\UserModel;
use Doctrine\DBAL\Connection;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Security\RecordPermissionChecker;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordCloneCommand;

/**
 * record_clone: Phase-9 macro tool that clones a Contao container record and
 * its child cascade in one server-side operation. Avoids the N+1 tool-call
 * pattern (`news_archive_create` + N×`news_read` + N×`news_create`) that
 * blows the rate-limit and context window on a normal LLM-orchestrated run.
 *
 * Phase-9.2 scope: tl_news_archive (cascades to tl_news). Further entities
 * (tl_calendar → tl_calendar_events, tl_faq_category → tl_faq, tl_page →
 * tl_article → tl_content) follow in 9.4 by registering additional
 * EntityClonerInterface implementations under the same tagged-iterator.
 *
 * Phase 9.5 (2026-05-06): Editor-Zugang. Source-Voter via
 * RecordPermissionChecker (gleiche Patterns wie NewsTool/PageTool/...,
 * nur zentral) + Container-Anlage-Voter (newp/calp/faqp 'create' bzw.
 * USER_CAN_EDIT_PAGE_HIERARCHY auf source.pid für tl_page).
 */
#[AsTool(
    'record_clone',
    'Clone a Contao container record and its full child cascade in one server-side operation. '
    .'Returns the new root id and the count of cloned children. '
    .'Supported sourceTable values: tl_news_archive (cascades to tl_news), tl_calendar (cascades to tl_calendar_events), tl_faq_category (cascades to tl_faq), tl_page (cascades to tl_article and their tl_content elements; nested content children inside accordion/colset/grouped layouts are included automatically). '
    .'Set recursive=true on tl_page to also clone the full descendant subpage tree (capped at depth 10 / 50 total pages); ignored for the other tables which have no subtree semantics. '
    .'Children are created as drafts (published=0) so the operator can review/translate before publishing. '
    .'Use this instead of issuing many individual create calls for bulk-copy workflows. '
    .'Editor permission rules: caller needs (a) module access on the source container, (b) the matching `*p`-create perm (newp/calp/faqp) for tl_news_archive/tl_calendar/tl_faq_category, or USER_CAN_EDIT_PAGE_HIERARCHY on the source.pid for tl_page. With recursive=true on tl_page every subpage in the source tree must be edit-accessible — the call refuses up-front otherwise (no partial clones).',
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
     * Mirrors the Per-Table-Module map in RecordListTool::TABLE_MODULE.
     *
     * @var array<string, string> table => required backend module
     */
    private const TABLE_MODULE = [
        'tl_news_archive' => 'news',
        'tl_calendar'     => 'calendar',
        'tl_faq_category' => 'faq',
        'tl_page'         => 'page',
    ];

    /**
     * Phase 9.5.5 — Auto-Grant nach Container-Anlage. Mapping Source-Table →
     * tl_user-Allow-List-Feld. Nach erfolgreichem Klon wird der neue
     * Container-ID an die Liste des aktuellen non-admin-Users angehängt,
     * damit er sich nicht selbst aussperrt. tl_page bewusst nicht enthalten:
     * pagemounts hat eine andere Semantik (Sichtbarkeit im Tree statt
     * einfacher Allow-List), separate Behandlung wäre nötig.
     *
     * @var array<string, string>
     */
    private const AUTO_GRANT_FIELD = [
        'tl_news_archive' => 'news',
        'tl_calendar'     => 'calendars',
        'tl_faq_category' => 'faqs',
    ];

    /**
     * Source-Table des laufenden cloneRecord-Calls. postProcessDecoded()
     * braucht die Info, hat sie aber nicht aus dem decoded-Array.
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
     * Phase 9.5: sichtbar wenn der User mind. ein Source-Modul mounted hat.
     * Per-Aufruf-Voter (assertSourceAccess + assertCanCreateContainer)
     * entscheidet dann, ob die konkrete Tabelle/ID erlaubt ist.
     */
    public function isAccessibleBy(BackendUser $user): bool
    {
        if ($user->isAdmin) {
            return true;
        }
        $userModules = (array) ($user->modules ?? []);
        foreach (self::TABLE_MODULE as $module) {
            if (\in_array($module, $userModules, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string                              $sourceTable    Container table to clone (tl_news_archive, tl_calendar, tl_faq_category, tl_page)
     * @param int                                 $sourceId       ID of the source container record
     * @param array<string, scalar|null>|string   $modifications  Field overrides for the cloned root record, e.g. {"title": "Pressemitteilungen 2026"}. Allowed fields per table are constrained server-side; unknown fields are silently dropped. Accepts an object/array OR a JSON-encoded string — symfony/ai's JSON-schema view of `array` lets Claude pick either.
     * @param bool                                $recursive      For container-of-container tables (currently only tl_page), also clone the entire descendant tree (subpages with their articles+content) under the new root. Capped at depth 10 / 50 total pages. Ignored for tables without subtree semantics. Default: false (only direct cascade).
     */
    public function cloneRecord(string $sourceTable, int $sourceId, array|string $modifications = [], bool $recursive = false): string
    {
        $user = $this->requireBackendUser();

        if (!isset(self::TABLE_MODULE[$sourceTable])) {
            throw new ToolAccessDeniedException(
                \sprintf('Tabelle "%s" wird vom record_clone-Tool nicht unterstützt.', $sourceTable)
            );
        }

        // Phase 9.5: Source-Voter (Lese-Recht auf den Container, der geklont wird)
        // + Container-Anlage-Voter (darf Editor überhaupt einen neuen Container
        // anlegen). Beides per RecordPermissionChecker, das die existierenden
        // Voter-Patterns aus den Single-Record-Tools wiederverwendet.
        $this->permissionChecker->assertRecordAccess($user, $sourceTable, $sourceId, 'edit');
        $this->permissionChecker->assertCanCreateContainer($user, $sourceTable, $sourceId);

        // Phase 9.5.3: bei tl_page recursive=true muss der Editor jede Subpage
        // im Source-Tree edit-berechtigt sein, weil der Core-Cloner aktuell
        // keine Skip-Liste akzeptiert (Pre-Flight statt Skip-mit-Note). Zeigen
        // wir refused-IDs explizit, damit Claude/Operator den Scope anpassen
        // können bevor sie noch mal probieren. Cap = depth 10 / 50 Seiten —
        // identisch zum Core-Cloner.
        if ($recursive && 'tl_page' === $sourceTable && !$user->isAdmin) {
            $this->assertSubtreeAccess($user, $sourceId);
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

        $modsJson = json_encode($modifications, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (false === $modsJson) {
            $modsJson = '{}';
        }

        $args = [
            '--source-table'  => $sourceTable,
            '--source-id'     => (string) $sourceId,
            '--modifications' => $modsJson,
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
     * Phase 9.5.5: nach erfolgreichem Klon dem aktuellen non-admin-User die
     * neue Container-ID in seine Allow-List eintragen — sonst hat er gerade
     * was angelegt, das er selbst nicht mehr sehen darf. Stock Contao macht
     * das per `onsubmit_callback` im DCA, der hier aber nicht durchläuft
     * (der Cloner schreibt direkt via Model::save()).
     *
     * Eingriff im post-process Hook, damit der wrapping-Wrapper im
     * runCommand uns nicht den Decoder-Zugriff aufs Result wegnimmt.
     */
    protected function postProcessDecoded(array &$decoded, string $toolName): void
    {
        if ('record_clone' !== $toolName || null === $this->pendingSourceTable) {
            return;
        }
        $field = self::AUTO_GRANT_FIELD[$this->pendingSourceTable] ?? null;
        if (null === $field) {
            return; // tl_page → kein Auto-Grant
        }
        $newId = (int) ($decoded['id'] ?? 0);
        if (0 === $newId) {
            return;
        }
        $user = $this->getCurrentBackendUser();
        if (null === $user || $user->isAdmin) {
            return;
        }

        $this->framework->initialize();
        $userModel = UserModel::findById((int) $user->id);
        if (null === $userModel) {
            return;
        }
        $current = StringUtil::deserialize($userModel->{$field}, true);
        $key     = (string) $newId;
        if (\in_array($key, $current, true)) {
            return;
        }
        $current[] = $key;
        $userModel->{$field} = serialize($current);
        $userModel->save();

        // Sichtbar im Tool-Result, damit Claude dem User Bescheid geben kann.
        $decoded['auto_granted'] = [
            'user_id'  => (int) $user->id,
            'username' => (string) $user->username,
            'field'    => $field,
            'added_id' => $newId,
        ];
    }

    /**
     * BFS über Subseiten (gleicher Cap wie der Core-Cloner: Tiefe 10 / 50
     * Knoten), pro Knoten USER_CAN_EDIT_PAGE-Voter. Bei einem einzigen Refused
     * harter Abbruch mit Liste der ersten N refused-IDs — Editor sieht, was
     * fehlt, statt nach dem Klon eine "Schweizer-Käse"-Struktur zu finden.
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

            $reason = $this->permissionChecker->recordAccessDenialReason(
                $user, 'tl_page', $pageId, 'edit'
            );
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
                'Recursive Clone abgebrochen — keine Edit-Rechte auf folgenden Subseiten: %s. '
                .'Nutze recursive=false oder bitte einen Admin um Zugriff.',
                $list
            ));
        }
    }
}
