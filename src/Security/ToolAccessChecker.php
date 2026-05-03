<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Security;

use Contao\BackendUser;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Tool\AbstractCoreCommandTool;

class ToolAccessChecker
{
    /**
     * Tools that require BackendUser::isAdmin === true on top of the module check.
     * All destructive (delete) operations are admin-only by default — editors with
     * a CRUD module can create/update/read but never delete via the agent. They
     * can still delete via the regular backend module (which has a confirm dialog).
     *
     * @var list<string>
     */
    private const ADMIN_ONLY_TOOLS = [
        'news_delete',
        'page_delete',
        'article_delete',
        'content_delete',
        // Phase 9.2: container-level macro creations (new news archive cascade,
        // later: calendar / faq-category / page-tree). Admins only — editors
        // get cloning of individual entries inside their existing scope via
        // a separate macro in Phase 9.4+.
        'record_clone',
        // Phase 9.3: bulk text rewrite via inner LLM call. Admin-only because
        // each invocation consumes the user's Anthropic credits per editorial
        // field × N records; operator should be aware of the cost.
        'record_rewrite',
    ];

    /**
     * Maps tool names (matches the AsTool name) to the Contao backend permissions
     * required to invoke them. `module` must be in $user->modules. `op` describes the
     * intent for human-readable denial messages; the underlying Contao DCA still
     * enforces row-level checks when the wrapped command runs.
     *
     * @var array<string, array{module: string, op: string}>
     */
    private const TOOL_MAP = [
        // News
        'news_create' => ['module' => 'news',     'op' => 'create'],
        'news_update' => ['module' => 'news',     'op' => 'edit'],
        'news_delete' => ['module' => 'news',     'op' => 'delete'],
        'news_read'   => ['module' => 'news',     'op' => 'read'],
        // Pages / Articles / Content
        'page_create'    => ['module' => 'page',    'op' => 'create'],
        'page_update'    => ['module' => 'page',    'op' => 'edit'],
        'page_delete'    => ['module' => 'page',    'op' => 'delete'],
        'page_publish'   => ['module' => 'page',    'op' => 'edit'],
        'page_read'      => ['module' => 'page',    'op' => 'read'],
        'article_create' => ['module' => 'article', 'op' => 'create'],
        'article_update' => ['module' => 'article', 'op' => 'edit'],
        'article_delete' => ['module' => 'article', 'op' => 'delete'],
        'article_read'   => ['module' => 'article', 'op' => 'read'],
        'content_create' => ['module' => 'article', 'op' => 'create'],
        'content_update' => ['module' => 'article', 'op' => 'edit'],
        'content_delete' => ['module' => 'article', 'op' => 'delete'],
        'content_read'   => ['module' => 'article', 'op' => 'read'],
        // Module-gated meta-tools. dca_schema deliberately stays UNMAPPED so
        // any backend user can query field shapes for the tables they already
        // have scope on — the system prompt's accessible_tables list bounds
        // the disclosure surface. search_query / listing_config touch state
        // beyond a single table (full-text index, listing-module config) and
        // require the page module the same way the regular Contao backend
        // gates them.
        'search_query'   => ['module' => 'page', 'op' => 'read'],
        'listing_config' => ['module' => 'page', 'op' => 'read'],
    ];

    /**
     * @param iterable<AbstractCoreCommandTool> $tools
     */
    public function __construct(
        #[TaggedIterator('contao_ai_backend.tool')]
        private readonly iterable $tools = [],
    ) {
    }

    public function canUseTool(BackendUser $user, string $toolName): bool
    {
        // Admin-only tools (destructive operations) require the admin flag,
        // regardless of module membership.
        if (\in_array($toolName, self::ADMIN_ONLY_TOOLS, true) && !$user->isAdmin) {
            return false;
        }

        if ($user->isAdmin) {
            return true;
        }

        if (!isset(self::TOOL_MAP[$toolName])) {
            return false;
        }

        $requirement = self::TOOL_MAP[$toolName];
        $userModules = (array) ($user->modules ?? []);

        return \in_array($requirement['module'], $userModules, true);
    }

    /**
     * @throws ToolAccessDeniedException
     */
    public function assertCanUseTool(BackendUser $user, string $toolName): void
    {
        if (!$this->canUseTool($user, $toolName)) {
            if (\in_array($toolName, self::ADMIN_ONLY_TOOLS, true) && !$user->isAdmin) {
                throw new ToolAccessDeniedException(
                    \sprintf('Tool "%s" ist nur für Admin-Benutzer zugänglich.', $toolName)
                );
            }
            $required = self::TOOL_MAP[$toolName]['module'] ?? 'unknown';
            throw new ToolAccessDeniedException(
                \sprintf('Tool "%s" requires backend module "%s".', $toolName, $required)
            );
        }
    }

    /**
     * Lists ALL #[AsTool] names accessible to the given user. Filters PER tool name
     * (not just per class) so that admin-only sub-tools are excluded for non-admins
     * even if the parent class is otherwise accessible. Meta-tools (read-only, no
     * TOOL_MAP entry) are admitted via the parent class's isAccessibleBy() override.
     *
     * @return list<string>
     */
    public function listAllowedTools(BackendUser $user): array
    {
        $allowed = [];
        foreach ($this->tools as $tool) {
            if (!$tool->isAccessibleBy($user)) {
                continue;
            }
            foreach ($tool->getToolNames() as $name) {
                // For tools without a TOOL_MAP entry (meta-tools), trust the
                // class-level isAccessibleBy() decision. For mapped tools, run
                // the per-name check so admin-only sub-tools are filtered.
                if (isset(self::TOOL_MAP[$name]) && !$this->canUseTool($user, $name)) {
                    continue;
                }
                $allowed[$name] = true;
            }
        }
        return array_keys($allowed);
    }
}
