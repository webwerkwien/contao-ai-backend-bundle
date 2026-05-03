<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Doctrine\DBAL\Connection;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Webwerkwien\ContaoAiBackendBundle\Exception\AiConfigException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformBridgeInterface;
use Webwerkwien\ContaoAiBackendBundle\Service\Rewriter\EntityRewriterInterface;
use Webwerkwien\ContaoAiBackendBundle\Service\UserAiConfig;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\EventUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FaqUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\NewsUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageUpdateCommand;

/**
 * record_rewrite: Phase-9.3+9.4 macro tool that re-routes editorial text
 * fields through the inner LLM platform with operator-supplied instructions
 * and writes results back through the regular *_update command pipeline so
 * audit-trail (tl_version + --operator) stays identical to a manual edit.
 *
 * Plugin-Awareness: Update-Commands are nullable constructor args wired via
 * explicit `@?ServiceId` references in services.yaml. If a contao-bundle is
 * missing (no news, no calendar, no faq), the corresponding command service
 * isn't registered and Symfony passes null for that arg. Runtime then
 * refuses with "kein Update-Command für Tabelle X" instead of failing at
 * construct time.
 *
 * Phase-9.3 covers tl_news (single + recursive over tl_news_archive).
 * Phase-9.4 adds tl_calendar_events (single + recursive over tl_calendar)
 * and tl_faq (single + recursive over tl_faq_category).
 */
#[AsTool(
    'record_rewrite',
    'Rewrite editorial text fields of a Contao record (or all child records when recursive=true) using the configured LLM platform and the operator-supplied instructions. '
    .'Each field is sent as its own platform request, the LLM never sees more than one editorial unit at a time. '
    .'Supported single-record tables: tl_news, tl_calendar_events, tl_faq, tl_page, tl_article, tl_content. '
    .'Supported recursive container tables (use recursive=true): tl_news_archive (cascades to tl_news), tl_calendar (cascades to tl_calendar_events), tl_faq_category (cascades to tl_faq), tl_page (cascades to tl_article), tl_article (cascades to tl_content). '
    .'Updates are written through the regular *_update pipeline so tl_version and --operator audit are stamped exactly like a manual edit. '
    .'Returns a per-record summary listing fields updated and fields skipped (with reason).',
    method: 'rewriteRecord',
)]
class RecordRewriteTool extends AbstractCoreCommandTool
{
    /**
     * Container -> child mapping for recursive=true. The optional `ptable_filter`
     * narrows the WHERE clause for tables (like tl_content) where pid alone is
     * ambiguous because they are polymorphically attached via pid+ptable.
     *
     * @var array<string, array{child_table: string, pid_column: string, ptable_filter?: string}>
     */
    private const CONTAINER_CHILD_MAP = [
        'tl_news_archive'  => ['child_table' => 'tl_news',             'pid_column' => 'pid'],
        'tl_calendar'      => ['child_table' => 'tl_calendar_events',  'pid_column' => 'pid'],
        'tl_faq_category'  => ['child_table' => 'tl_faq',              'pid_column' => 'pid'],
        'tl_page'          => ['child_table' => 'tl_article',          'pid_column' => 'pid'],
        'tl_article'       => ['child_table' => 'tl_content',          'pid_column' => 'pid', 'ptable_filter' => 'tl_article'],
    ];

    private const MAX_RECURSIVE_RECORDS = 30;

    /**
     * @param iterable<EntityRewriterInterface>  $rewriters
     * @param iterable<PlatformBridgeInterface>  $platformBridges
     */
    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        #[TaggedIterator('contao_ai_backend.entity_rewriter')]
        private readonly iterable $rewriters,
        #[TaggedIterator('contao_ai_backend.platform_bridge')]
        private readonly iterable $platformBridges,
        private readonly UserAiConfig $userConfig,
        private readonly ?NewsUpdateCommand $newsUpdate = null,
        private readonly ?EventUpdateCommand $eventUpdate = null,
        private readonly ?FaqUpdateCommand $faqUpdate = null,
        // Page/Article/Content sind Teil von contao/core-bundle, immer da.
        // Trotzdem nullable für Robustheit, falls jemand das Bundle ohne
        // core-bundle (theoretisch) deployt.
        private readonly ?PageUpdateCommand $pageUpdate = null,
        private readonly ?ArticleUpdateCommand $articleUpdate = null,
        private readonly ?ContentUpdateCommand $contentUpdate = null,
    ) {
        parent::__construct($accessChecker, $tokenChecker);
    }

    public function getToolName(): string
    {
        return 'record_rewrite';
    }

    /**
     * @param string $table         A single-record table or a container table (with recursive=true)
     * @param int    $id            Record ID. Single-record path: the row to rewrite. Recursive path: the container ID whose children are rewritten.
     * @param string $instructions  Operator's natural-language directive, e.g. "auf Englisch in Du-Form" or "kürzer und mit professionellem Ton"
     * @param bool   $recursive     When true and table is a known container, walk all children (max 30) and rewrite each.
     */
    public function rewriteRecord(string $table, int $id, string $instructions, bool $recursive = false): string
    {
        $user = $this->requireBackendUser();
        if (!$user->isAdmin) {
            throw new ToolAccessDeniedException('record_rewrite ist nur für Admin-Benutzer zugänglich.');
        }

        $instructions = trim($instructions);
        if ('' === $instructions) {
            throw new ToolExecutionException('record_rewrite benötigt nicht-leere Anweisungen.');
        }

        $config = $this->userConfig->getForUser($user);
        if (!$config->hasApiKey()) {
            throw new AiConfigException('Im Benutzerprofil ist kein KI-API-Key hinterlegt.');
        }
        $bridge   = $this->resolveBridge($config->platform);
        $platform = $bridge->createPlatform($config->getApiKey());
        $model    = $bridge->getDefaultModel();

        $targets   = $this->resolveTargetIds($table, $id, $recursive);
        $rewriter  = $this->resolveRewriter($targets['rewriter_table']);
        $writeCmd  = $this->resolveWriteCommand($targets['rewriter_table']);

        $results = [];
        foreach ($targets['ids'] as $recordId) {
            try {
                $rewritten = $rewriter->rewrite($recordId, $instructions, $platform, $model);
            } catch (\Throwable $e) {
                $results[] = [
                    'id'     => $recordId,
                    'status' => 'error',
                    'error'  => $e->getMessage(),
                ];
                continue;
            }

            if ([] === $rewritten['fields']) {
                $results[] = [
                    'id'      => $recordId,
                    'status'  => 'no_changes',
                    'skipped' => $rewritten['skipped'],
                ];
                continue;
            }

            try {
                $this->runCommand(
                    $writeCmd,
                    [
                        'id'    => (string) $recordId,
                        '--set' => $this->fieldMapToSetOptions($rewritten['fields']),
                    ],
                    'record_rewrite',
                );
                $results[] = [
                    'id'      => $recordId,
                    'status'  => 'ok',
                    'updated' => array_keys($rewritten['fields']),
                    'skipped' => $rewritten['skipped'],
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'id'     => $recordId,
                    'status' => 'write_failed',
                    'error'  => $e->getMessage(),
                ];
            }
        }

        $payload = [
            'status'    => 'ok',
            'table'     => $targets['rewriter_table'],
            'recursive' => $recursive,
            'count'     => \count($results),
            'capped'    => $targets['capped'],
            'results'   => $results,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (false === $json) {
            throw new ToolExecutionException('record_rewrite konnte das Ergebnis nicht serialisieren.');
        }
        return "<tool_output_data tool=\"record_rewrite\">\n{$json}\n</tool_output_data>";
    }

    /**
     * @return array{ids: list<int>, rewriter_table: string, capped: bool}
     */
    private function resolveTargetIds(string $table, int $id, bool $recursive): array
    {
        if (!$recursive) {
            return ['ids' => [$id], 'rewriter_table' => $table, 'capped' => false];
        }

        if (!isset(self::CONTAINER_CHILD_MAP[$table])) {
            throw new ToolExecutionException(\sprintf(
                'Tabelle "%s" hat im record_rewrite-Tool kein recursive-Kind-Mapping.', $table
            ));
        }
        $childTable   = self::CONTAINER_CHILD_MAP[$table]['child_table'];
        $pidColumn    = self::CONTAINER_CHILD_MAP[$table]['pid_column'];
        $ptableFilter = self::CONTAINER_CHILD_MAP[$table]['ptable_filter'] ?? null;

        $this->framework->initialize();
        $sql    = \sprintf('SELECT id FROM `%s` WHERE `%s` = ?', $childTable, $pidColumn);
        $params = [$id];
        if (null !== $ptableFilter) {
            $sql      .= ' AND `ptable` = ?';
            $params[]  = $ptableFilter;
        }
        $sql .= ' ORDER BY id ASC';
        $rows = $this->connection->fetchAllAssociative($sql, $params);
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);

        // Spezialfall tl_article -> tl_content: nested content-elements
        // (ptable=tl_content) sind die Kinder von Container-Elementen wie
        // accordion/colset/grouped layouts. Wer "alle Inhalte des Articles
        // umschreiben" sagt, meint typischerweise auch diese — sonst bleiben
        // die Sub-Texte im Akkordeon original. BFS bis keine neuen Kinder
        // mehr auftauchen.
        if ('tl_article' === $table && 'tl_content' === $childTable) {
            $queue = $ids;
            while ([] !== $queue) {
                $childRows = $this->connection->fetchAllAssociative(
                    \sprintf(
                        "SELECT id FROM `tl_content` WHERE `pid` IN (%s) AND `ptable` = ?",
                        implode(',', array_fill(0, \count($queue), '?'))
                    ),
                    [...$queue, 'tl_content']
                );
                $newIds = array_map(static fn(array $r): int => (int) $r['id'], $childRows);
                if ([] === $newIds) {
                    break;
                }
                $ids   = array_merge($ids, $newIds);
                $queue = $newIds;
            }
        }

        $capped = false;
        if (\count($ids) > self::MAX_RECURSIVE_RECORDS) {
            $ids = \array_slice($ids, 0, self::MAX_RECURSIVE_RECORDS);
            $capped = true;
        }
        return ['ids' => $ids, 'rewriter_table' => $childTable, 'capped' => $capped];
    }

    private function resolveRewriter(string $table): EntityRewriterInterface
    {
        foreach ($this->rewriters as $rewriter) {
            if ($rewriter->supports($table)) {
                return $rewriter;
            }
        }
        throw new ToolExecutionException(\sprintf(
            'Kein Rewriter für Tabelle "%s" registriert.', $table
        ));
    }

    private function resolveWriteCommand(string $table): Command
    {
        $cmd = match ($table) {
            'tl_news'            => $this->newsUpdate,
            'tl_calendar_events' => $this->eventUpdate,
            'tl_faq'             => $this->faqUpdate,
            'tl_page'            => $this->pageUpdate,
            'tl_article'         => $this->articleUpdate,
            'tl_content'         => $this->contentUpdate,
            default              => null,
        };
        if (null === $cmd) {
            throw new ToolExecutionException(\sprintf(
                'Kein Update-Command für Tabelle "%s" registriert (zugehöriges Contao-Bundle nicht installiert).',
                $table
            ));
        }
        return $cmd;
    }

    private function resolveBridge(string $platform): PlatformBridgeInterface
    {
        foreach ($this->platformBridges as $bridge) {
            if ($bridge->getName() === $platform) {
                return $bridge;
            }
        }
        throw new AiConfigException(\sprintf('Unbekannte KI-Plattform "%s".', $platform));
    }

    /**
     * @param array<string, string> $fields
     * @return list<string>
     */
    private function fieldMapToSetOptions(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                continue;
            }
            $out[] = $key . '=' . $value;
        }
        return $out;
    }
}
