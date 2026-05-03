<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
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
 * Admin-only: container-level creations (new news archive, new calendar)
 * are admin territory in stock Contao — editors get scoped to existing
 * containers via the regular module-permission system. Editors will get a
 * separate "clone individual entries" macro later (Phase 9.4+) that fits
 * inside their existing scope.
 */
#[AsTool(
    'record_clone',
    'Clone a Contao container record and its full child cascade in one server-side operation. '
    .'Returns the new root id and the count of cloned children. '
    .'Supported sourceTable values: tl_news_archive (cascades to tl_news), tl_calendar (cascades to tl_calendar_events), tl_faq_category (cascades to tl_faq). '
    .'Children are created as drafts (published=0) so the operator can review/translate before publishing. '
    .'Use this instead of issuing many individual create calls for bulk-copy workflows.',
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
    ];

    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
        private readonly RecordCloneCommand $cloneCommand,
    ) {
        parent::__construct($accessChecker, $tokenChecker);
    }

    public function getToolName(): string
    {
        return 'record_clone';
    }

    // Kein isAccessibleBy()-Override: der Default aus AbstractCoreCommandTool
    // delegiert an ToolAccessChecker::canUseTool(), das `record_clone` über
    // ADMIN_ONLY_TOOLS gegen Non-Admins filtert. Eigener Override mit
    // `$user->isAdmin ?? false` lieferte in Produktion false (Contao Model
    // __isset()-Pfad reagiert anders als bei direkter Property-Lookup),
    // weshalb das Tool selbst für Admins nicht im Prompt landete.

    /**
     * @param string                              $sourceTable    Container table to clone (currently: tl_news_archive)
     * @param int                                 $sourceId       ID of the source container record
     * @param array<string, scalar|null>|string   $modifications  Field overrides for the cloned root record, e.g. {"title": "Pressemitteilungen 2026"}. Allowed fields per table are constrained server-side; unknown fields are silently dropped. Accepts an object/array OR a JSON-encoded string — symfony/ai's JSON-schema view of `array` lets Claude pick either.
     */
    public function cloneRecord(string $sourceTable, int $sourceId, array|string $modifications = []): string
    {
        $user = $this->requireBackendUser();
        if (!$user->isAdmin) {
            throw new ToolAccessDeniedException(
                'record_clone ist nur für Admin-Benutzer zugänglich.'
            );
        }

        if (!isset(self::TABLE_MODULE[$sourceTable])) {
            throw new ToolAccessDeniedException(
                \sprintf('Tabelle "%s" wird vom record_clone-Tool nicht unterstützt.', $sourceTable)
            );
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

        return $this->runCommand($this->cloneCommand, [
            '--source-table'  => $sourceTable,
            '--source-id'     => (string) $sourceId,
            '--modifications' => $modsJson,
        ], 'record_clone');
    }
}
