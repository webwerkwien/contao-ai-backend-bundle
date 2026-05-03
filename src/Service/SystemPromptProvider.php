<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service;

use Contao\BackendUser;
use Webwerkwien\ContaoAiBackendBundle\Tool\AbstractCoreCommandTool;

class SystemPromptProvider
{
    private ?string $cachedTemplate = null;

    public function __construct(
        private readonly string $promptFile,
    ) {
    }

    /**
     * Names of every tool the bundle ships, regardless of who can use it. Used
     * to render the "actions explicitly NOT available to you" section so the
     * model can't extrapolate generic CRUD coverage from training data.
     *
     * @var list<string>
     */
    private const ALL_KNOWN_TOOL_NAMES = [
        'news_create', 'news_update', 'news_delete', 'news_read',
        'page_create', 'page_update', 'page_delete', 'page_publish', 'page_read',
        'article_create', 'article_update', 'article_delete', 'article_read',
        'content_create', 'content_update', 'content_delete', 'content_read',
        'record_list',
        'record_clone',
        'record_rewrite',
        'dca_schema', 'listing_config', 'search_query',
    ];

    /**
     * Maps a Contao backend module to the DCA tables the user gains scope on.
     * Mirrors RecordListTool::TABLE_MODULE (inverted) — kept here too so the
     * system prompt can render a per-user accessible-tables list without
     * pulling RecordListTool as a dependency. Update both when adding a table.
     *
     * @var array<string, list<string>>
     */
    private const MODULE_TABLES = [
        'news'     => ['tl_news', 'tl_news_archive'],
        'page'     => ['tl_page'],
        'article'  => ['tl_article', 'tl_content'],
        'calendar' => ['tl_calendar', 'tl_calendar_events'],
        'faq'      => ['tl_faq', 'tl_faq_category'],
        'files'    => ['tl_files'],
    ];

    /**
     * @return list<string> Tables the user may name in record_list / dca_schema
     *   based on their backend module assignments. Admins see everything; the
     *   intent for non-admins is that the LLM only ever sees and names tables
     *   the user could already see in the regular Contao backend, so a stray
     *   `record_list tl_user` from the model triggers an immediate refusal
     *   rather than even leaking the existence of tables the user has no
     *   business knowing about.
     */
    private function accessibleTablesFor(BackendUser $user): array
    {
        if ($user->isAdmin) {
            $all = [];
            foreach (self::MODULE_TABLES as $tables) {
                foreach ($tables as $t) {
                    $all[$t] = true;
                }
            }
            return array_keys($all);
        }
        $modules = (array) ($user->modules ?? []);
        $scoped = [];
        foreach ($modules as $module) {
            foreach (self::MODULE_TABLES[$module] ?? [] as $t) {
                $scoped[$t] = true;
            }
        }
        return array_keys($scoped);
    }

    /**
     * @param list<string> $allowedToolNames Already-filtered names from
     *   ToolAccessChecker::listAllowedTools — NOT raw class-level AsTool names.
     *   The class-level set leaks admin-only sub-tools (news_delete) into the
     *   prompt for non-admin users, which made the model embellish its
     *   capability description and start unsolvable confirmation loops.
     */
    public function forUser(BackendUser $user, array $allowedToolNames): string
    {
        // H-5: sanitize all template substitutions — values flow into the system prompt
        // unmodified, so a hostile username/language could inject Markdown sections or
        // override prior instructions. Identifier-shaped fields are regex-validated;
        // the tool list is filtered to a strict identifier alphabet.
        $username = $this->sanitizeIdentifier((string) ($user->username ?? ''), 64) ?: '(invalid)';
        $locale   = $this->sanitizeIdentifier((string) ($user->language ?? ''), 16) ?: 'de';
        $isAdmin  = $user->isAdmin ? 'yes' : 'no';

        $allowed = [];
        foreach ($allowedToolNames as $name) {
            if (\is_string($name) && preg_match('/^[a-z0-9_]{1,64}$/', $name)) {
                $allowed[$name] = true;
            }
        }
        $allowedNames = array_keys($allowed);
        $deniedNames  = array_values(array_diff(self::ALL_KNOWN_TOOL_NAMES, $allowedNames));

        $tools = $allowedNames ? '- ' . implode("\n- ", $allowedNames) : '(none)';
        $denied = $deniedNames ? '- ' . implode("\n- ", $deniedNames) : '(none)';

        $tables = $this->accessibleTablesFor($user);
        $accessibleTables = $tables ? '- ' . implode("\n- ", $tables) : '(none — record_list/dca_schema not usable)';

        return strtr($this->loadTemplate(), [
            '{{username}}'           => $username,
            '{{locale}}'             => $locale,
            '{{admin}}'              => $isAdmin,
            '{{tools}}'              => $tools,
            '{{tools_denied}}'       => $denied,
            '{{accessible_tables}}'  => $accessibleTables,
        ]);
    }

    private function sanitizeIdentifier(string $value, int $maxLen): string
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,' . $maxLen . '}$/', $value)) {
            return '';
        }
        return $value;
    }

    private function loadTemplate(): string
    {
        if (null !== $this->cachedTemplate) {
            return $this->cachedTemplate;
        }

        $path = $this->resolvePath();
        if (null === $path) {
            throw new \RuntimeException(\sprintf('System prompt file not found at "%s" nor in the local fallback path.', $this->promptFile));
        }

        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException(\sprintf('Failed to read system prompt file at "%s".', $path));
        }

        return $this->cachedTemplate = $contents;
    }

    private function resolvePath(): ?string
    {
        if (is_readable($this->promptFile)) {
            return $this->promptFile;
        }
        $local = \dirname(__DIR__) . '/Resources/prompts/system.md';
        if (is_readable($local)) {
            return $local;
        }
        return null;
    }
}
