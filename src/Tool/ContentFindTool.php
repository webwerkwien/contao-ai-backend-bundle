<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
use Webwerkwien\ContaoAiBackendBundle\Security\TableAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\ConsoleRunner;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;

/**
 * Finds content elements by their text (v0.20.0), through the core's contao:content:find
 * (core v1.10.0) — offered only where the core has it.
 *
 * Until now the chat found an element through the search index or by listing table after
 * table: on showcase 9–10 calls and half a minute for one slider headline (2026-10-19). The
 * index answers pages, not elements, and a page with `noindex` is never in it.
 *
 * The core trusts its operator; here every hit goes through Contao's voters as a read of
 * that element, and what the user may not read is left out — counted, not named.
 */
#[AsTool(
    'content_find',
    'Find content elements by their text: the headline, text or HTML that contains it (at least 3 characters, case-insensitive). '
    .'Each result names the element (id, type, field, snippet, invisible) with its article, page and the website root with its language. '
    .'Use this to find the element a user means by a text before reading or changing it; on a site with several languages the root\'s '
    .'language tells which version shows the text. Where pages are linked across languages, each result also names the versions '
    .'of its page and article in the other languages (versions); roots lists the website roots. root limits the search to one '
    .'website root, e.g. one language.',
    method: 'find',
)]
class ContentFindTool extends AbstractCoreCommandTool
{
    use FiltersLanguageVersions;

    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
        private readonly ConsoleRunner $console,
        private readonly TableAccess $tables,
    ) {
        parent::__construct($accessChecker, $tokenChecker);
    }

    public function getToolName(): string
    {
        return 'content_find';
    }

    /**
     * Only where the core has the command (v1.10.0); this bundle allows older cores.
     */
    public function isAccessibleBy(BackendUser $user): bool
    {
        return $this->console->has('contao:content:find') && parent::isAccessibleBy($user);
    }

    /**
     * @param string $text  The text to find, at least 3 characters
     * @param int    $root  Only elements below this website root (0 = all)
     * @param int    $limit Max results (1–50)
     */
    #[AiContract(writes: false, tables: ['tl_content'], trace: [])]
    public function find(string $text, int $root = 0, int $limit = 20): string
    {
        $this->assertAccess('content_find');
        $user = $this->requireBackendUser();

        if (!$this->tables->canAccess($user, 'tl_content')) {
            throw new ToolAccessDeniedException('Kein Zugriff auf Inhaltselemente.');
        }

        $input = ['command' => 'contao:content:find', 'query' => $text, '--limit' => (string) max(1, min(50, $limit))];
        if ($root > 0) {
            $input['--root'] = (string) $root;
        }

        [$code, $output] = $this->console->run($input);

        $decoded = json_decode(trim($output), true);
        if (0 !== $code || !\is_array($decoded) || 'ok' !== ($decoded['status'] ?? null)) {
            return $this->answer($code, $output, 'content_find');
        }
        // Fail closed: an answer without its result list does not go to the model unfiltered
        // (Fable review 2026-10-20).
        if (!\is_array($decoded['results'] ?? null)) {
            throw new ToolExecutionException('Tool "content_find": contao:content:find answered without a result list.');
        }

        $readable = [];
        $hidden = 0;
        foreach ($decoded['results'] as $hit) {
            if (\is_array($hit) && $this->mayRead($user, (int) ($hit['id'] ?? 0))) {
                $readable[] = $this->withReadableVersions($hit);
            } else {
                ++$hidden;
            }
        }
        if (isset($decoded['roots'])) {
            [$decoded['roots']] = $this->readable('tl_page', $decoded['roots']);
        }
        $decoded['results'] = $readable;
        $decoded['count'] = \count($readable);
        if ($hidden > 0) {
            $decoded['hidden'] = $hidden;
            $decoded['note'] = \sprintf('%d further element(s) contain the text but are outside what this user may read.', $hidden);
        }

        return $this->answer(0, (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'content_find');
    }

    /**
     * The linked versions core v1.11.0 names per hit (page and article, or a news entry's
     * or event's), reduced to what the user may read; a shape this does not know is
     * dropped rather than passed on unfiltered.
     *
     * @param array<string, mixed> $hit
     *
     * @return array<string, mixed>
     */
    private function withReadableVersions(array $hit): array
    {
        if (!\array_key_exists('versions', $hit)) {
            return $hit;
        }

        $tables = ['page' => 'tl_page', 'article' => 'tl_article'];
        $entryTable = $hit['parent']['table'] ?? null;
        if (\in_array($entryTable, ['tl_news', 'tl_calendar_events'], true)) {
            $tables['entry'] = $entryTable;
        }

        $versions = [];
        $hidden = 0;
        foreach (\is_array($hit['versions']) ? $hit['versions'] : [] as $kind => $list) {
            if (!isset($tables[$kind])) {
                continue;
            }
            [$versions[$kind], $left] = $this->readable($tables[$kind], $list);
            $hidden += $left;
        }

        $hit['versions'] = $versions;
        if ($hidden > 0) {
            $hit['hiddenVersions'] = $hidden;
        }

        return $hit;
    }

    /**
     * As content_read decides it: Contao's voters on a read of the element, and for an
     * editor a parent table whose voter is installed (DataContainerAccess).
     */
    private function mayRead(BackendUser $user, int $id): bool
    {
        $record = $this->dcAccess->fetchRecord('tl_content', $id);
        if (null === $record || !$this->dcAccess->isGranted(new ReadAction('tl_content', $record))) {
            return false;
        }

        return $user->isAdmin || null === $this->dcAccess->contentParentDenial($record);
    }
}
