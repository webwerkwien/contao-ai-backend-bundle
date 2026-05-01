<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\PageModel;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordListCommand;

#[AsTool(
    'record_list',
    'List records from a Contao table with pagination and ordering. Use this to get an overview before *_read. '
    .'Allowed tables: tl_news, tl_news_archive, tl_page, tl_article, tl_content, tl_calendar, tl_calendar_events, tl_files. '
    .'Returns id + a curated set of columns per table. '
    .'Supports filter (e.g. pid=5), order, limit (max 50), offset. '
    .'Order column choice: for "newest/most-recently-edited" use `tstamp DESC` (last-modified, always unique). '
    .'For "next/upcoming publication" use `date DESC`. The default is `id DESC` which is also a safe '
    .'"newest-created" proxy. Avoid ordering by `date` alone for "newest" — Contao stores publication dates '
    .'at midnight, so multiple same-day records tie and the agent picking the first row is undefined.',
    method: 'listRecords',
)]
class RecordListTool extends AbstractCoreCommandTool
{
    /**
     * Tables editors may overview. tl_user / tl_module / tl_layout etc. stay
     * unreachable on purpose — same allow-list as MetaTool::ALLOWED_DCA_TABLES.
     */
    private const ALLOWED_TABLES = [
        'tl_news', 'tl_news_archive',
        'tl_page', 'tl_article', 'tl_content',
        'tl_calendar', 'tl_calendar_events',
        'tl_files',
    ];

    /**
     * Map allowed tables to the backend module they belong to. Editors must have
     * the module mounted to list records of that table — mirrors how the regular
     * backend hides sections a user has no access to.
     */
    private const TABLE_MODULE = [
        'tl_news'            => 'news',
        'tl_news_archive'    => 'news',
        'tl_page'            => 'page',
        'tl_article'         => 'article',
        'tl_content'         => 'article',
        'tl_calendar'        => 'calendar',
        'tl_calendar_events' => 'calendar',
        'tl_files'           => 'files',
    ];

    /**
     * Hard cap on rows returned per call. Lower than the Core max (100) so a
     * stray "give me everything" request can't blow the context budget.
     */
    private const MAX_LIMIT = 50;

    /**
     * Sensitive column names that are stripped from result rows even if the
     * caller explicitly requested them — defense-in-depth on top of the DCA
     * allow-list (mirrors MetaTool::SENSITIVE_FIELD_NAMES).
     */
    private const SENSITIVE_FIELD_NAMES = [
        'password', 'pwChange', 'session', 'sessionLifetime',
        'secret', 'privateKey', 'publicKey',
        'ai_api_key',
    ];

    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
        private readonly ContaoFramework $framework,
        private readonly RecordListCommand $listCommand,
    ) {
        parent::__construct($accessChecker, $tokenChecker);
    }

    public function getToolName(): string
    {
        return 'record_list';
    }

    /**
     * Read-only overview tool. Per-table module check happens inside list()
     * once we know which table the agent asked for.
     */
    public function isAccessibleBy(BackendUser $user): bool
    {
        return true;
    }

    /**
     * @param string                      $table   Contao table from the allow-list (e.g. tl_news)
     * @param int                         $limit   Max rows (1–50; default 20)
     * @param int                         $offset  Result offset (default 0)
     * @param string                      $order   ORDER BY clause, e.g. "tstamp DESC" or "id ASC"
     * @param array<string, scalar|null>  $filter  Equality filter, e.g. {"pid": 5, "published": 1}
     * @param list<string>                $fields  Columns to return; empty = curated default per table
     */
    public function listRecords(
        string $table,
        int $limit = 20,
        int $offset = 0,
        string $order = 'id DESC',
        array $filter = [],
        array $fields = [],
    ): string {
        if (!\in_array($table, self::ALLOWED_TABLES, true)) {
            throw new ToolAccessDeniedException(
                \sprintf('Tabelle "%s" ist für record_list nicht freigegeben.', $table)
            );
        }

        $this->assertModuleAccess($table);

        $args = [
            'table'    => $table,
            '--limit'  => (string) max(1, min(self::MAX_LIMIT, $limit)),
            '--offset' => (string) max(0, $offset),
            '--order'  => $order,
        ];
        if ([] !== $filter) {
            $args['--filter'] = $this->buildFilterArgs($filter);
        }
        if ([] !== $fields) {
            $args['--fields'] = implode(',', array_filter($fields, 'is_string'));
        }

        return $this->runCommand($this->listCommand, $args, 'record_list');
    }

    /**
     * Strip sensitive columns + filter results by per-record permissions
     * (H-9-style) so an editor cannot enumerate records they couldn't see in
     * the regular backend.
     */
    protected function postProcessDecoded(array &$decoded, string $toolName): void
    {
        if ('record_list' !== $toolName) {
            return;
        }

        $table   = $decoded['table']   ?? null;
        $results = $decoded['results'] ?? null;
        if (!\is_string($table) || !\is_array($results)) {
            return;
        }

        $user = $this->getCurrentBackendUser();
        if (null === $user) {
            $decoded['results'] = [];
            $decoded['count']   = 0;
            return;
        }

        // 1) Strip sensitive columns from every row (DiD).
        foreach ($results as $i => $row) {
            if (!\is_array($row)) {
                continue;
            }
            foreach (self::SENSITIVE_FIELD_NAMES as $name) {
                unset($row[$name]);
            }
            $results[$i] = $row;
        }

        // 2) Per-record filter — admins see everything.
        if (!$user->isAdmin) {
            $results = $this->filterByPermission($table, $results, $user);
        }

        $decoded['results']  = array_values($results);
        $decoded['count']    = \count($results);
        // total stays as the unfiltered DB count — informative for the agent
        // ("there are 120 news items, you can see 8 of them").
    }

    /**
     * Module-level gate: an editor without the matching module gets nothing.
     * Mirrors how MetaTool::searchQuery checks for the page module.
     */
    private function assertModuleAccess(string $table): void
    {
        $user = $this->requireBackendUser();
        if ($user->isAdmin) {
            return;
        }
        $module = self::TABLE_MODULE[$table] ?? null;
        if (null === $module) {
            throw new ToolAccessDeniedException(
                \sprintf('Tabelle "%s" hat kein zugeordnetes Backend-Modul.', $table)
            );
        }
        $modules = (array) ($user->modules ?? []);
        if (!\in_array($module, $modules, true)) {
            throw new ToolAccessDeniedException(
                \sprintf('record_list für "%s" benötigt das Backend-Modul "%s".', $table, $module)
            );
        }
    }

    /**
     * @param array<int|string, array<string, mixed>> $results
     * @return array<int|string, array<string, mixed>>
     */
    private function filterByPermission(string $table, array $results, BackendUser $user): array
    {
        $this->framework->initialize();

        return match ($table) {
            'tl_news', 'tl_news_archive' => array_filter(
                $results,
                fn ($row) => $this->canSeeNewsRow($table, $row, $user),
            ),
            'tl_page' => array_filter(
                $results,
                fn ($row) => \is_array($row) && $user->isAllowed(BackendUser::CAN_EDIT_PAGE, $row),
            ),
            'tl_article' => array_filter(
                $results,
                fn ($row) => $this->canSeeArticleRow($row, $user),
            ),
            'tl_content' => array_filter(
                $results,
                fn ($row) => $this->canSeeContentRow($row, $user),
            ),
            // tl_calendar / tl_files: module check (above) already enforced.
            default => $results,
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function canSeeNewsRow(string $table, mixed $row, BackendUser $user): bool
    {
        if (!\is_array($row)) {
            return false;
        }
        // tl_news_archive: id IS the archive id; tl_news: pid points to the archive.
        $archiveId = (int) ('tl_news_archive' === $table ? ($row['id'] ?? 0) : ($row['pid'] ?? 0));
        if (0 === $archiveId) {
            return false;
        }
        return $user->hasAccess($archiveId, 'news');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function canSeeArticleRow(mixed $row, BackendUser $user): bool
    {
        if (!\is_array($row)) {
            return false;
        }
        $pageId = (int) ($row['pid'] ?? 0);
        if (0 === $pageId) {
            return false;
        }
        $page = PageModel::findById($pageId);
        return null !== $page && $user->isAllowed(BackendUser::CAN_EDIT_PAGE, $page->row());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function canSeeContentRow(mixed $row, BackendUser $user): bool
    {
        if (!\is_array($row)) {
            return false;
        }
        $ptable = (string) ($row['ptable'] ?? 'tl_article');
        $pid    = (int) ($row['pid'] ?? 0);
        if (0 === $pid) {
            return false;
        }
        // Most content elements live below an article. Resolve to the page.
        if ('tl_article' === $ptable) {
            $article = \Contao\ArticleModel::findById($pid);
            if (null === $article) {
                return false;
            }
            $page = PageModel::findById((int) $article->pid);
            return null !== $page && $user->isAllowed(BackendUser::CAN_EDIT_PAGE, $page->row());
        }
        // Unknown ptable (custom modules) — be conservative.
        return false;
    }

    /**
     * Convert {field: value} into ["field=value", …] expected by RecordListCommand.
     *
     * @param array<string, scalar|null> $filter
     * @return list<string>
     */
    private function buildFilterArgs(array $filter): array
    {
        $out = [];
        foreach ($filter as $field => $value) {
            if (null === $value) {
                continue;
            }
            if (!\is_string($field) || 1 !== preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $field)) {
                throw new ToolAccessDeniedException(
                    \sprintf('Filter-Feldname "%s" ist ungültig.', (string) $field)
                );
            }
            $stringValue = (string) $value;
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F=\n\r]/', $stringValue)) {
                throw new ToolAccessDeniedException(
                    \sprintf('Filter-Wert für "%s" enthält ungültige Zeichen.', $field)
                );
            }
            $out[] = $field.'='.$stringValue;
        }
        return $out;
    }
}
