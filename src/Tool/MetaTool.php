<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiCoreBundle\Command\DcaSchemaCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ListingConfigCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\SearchQueryCommand;

#[AsTool('dca_schema',     'Read the DCA field definitions of a Contao table (tl_news, tl_page, …) so the agent knows which fields exist and their types', method: 'dcaSchema')]
#[AsTool('listing_config', 'Read the configuration of a Contao listing module (which fields are shown, sorting, filters)', method: 'listingConfig')]
#[AsTool('search_query',   'Run a Contao search query across the indexed content (full-text)', method: 'searchQuery')]
class MetaTool extends AbstractCoreCommandTool
{
    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
        private readonly DcaSchemaCommand $dcaCommand,
        private readonly ListingConfigCommand $listingCommand,
        private readonly SearchQueryCommand $searchCommand,
    ) {
        parent::__construct($accessChecker, $tokenChecker);
    }

    public function getToolName(): string
    {
        return 'dca_schema';
    }

    public function isAccessibleBy(BackendUser $user): bool
    {
        // Meta-tools are read-only and broadly useful — any backend user with chat access may use them.
        return true;
    }

    /**
     * H-8: Editors must not enumerate the schema of `tl_user` (password, session,
     * privateKey) or other admin-only tables. Tables outside the allow-list and
     * sensitive fields (`hideInput`/`encrypt`/password) get filtered before the
     * payload reaches the agent.
     */
    private const ALLOWED_DCA_TABLES = [
        'tl_news', 'tl_news_archive',
        'tl_page', 'tl_article', 'tl_content',
        'tl_calendar', 'tl_calendar_events',
        'tl_files',
    ];

    /**
     * Field names that must never appear in dca_schema/listing_config output.
     * Includes credential, session and crypto-key columns plus AI provider key.
     */
    private const SENSITIVE_FIELD_NAMES = [
        'password', 'pwChange', 'session', 'sessionLifetime',
        'secret', 'privateKey', 'publicKey',
        'ai_api_key',
    ];

    /**
     * @param string $table Contao table name, e.g. tl_news, tl_page
     */
    public function dcaSchema(string $table): string
    {
        if (!\in_array($table, self::ALLOWED_DCA_TABLES, true)) {
            throw new ToolAccessDeniedException(
                \sprintf('Tabelle "%s" ist für dca_schema nicht freigegeben.', $table)
            );
        }
        return $this->runCommand($this->dcaCommand, ['table' => $table], 'dca_schema');
    }

    /**
     * @param int $moduleId Module ID (tl_module)
     */
    public function listingConfig(int $moduleId): string
    {
        return $this->runCommand($this->listingCommand, ['id' => (string) $moduleId], 'listing_config');
    }

    /**
     * M-3: search_query returns the full Contao search index (member-only URLs,
     * internal redirects). Restrict to backend users with the `page` module so
     * the result mirrors what they could already see in the page tree.
     *
     * @param string $query Search query string
     */
    public function searchQuery(string $query): string
    {
        $user = $this->requireBackendUser();
        if (!$user->isAdmin) {
            $modules = (array) ($user->modules ?? []);
            if (!\in_array('page', $modules, true)) {
                throw new ToolAccessDeniedException(
                    'Suchindex nur für Benutzer mit Page-Modul zugänglich.'
                );
            }
        }
        return $this->runCommand($this->searchCommand, ['query' => $query], 'search_query');
    }

    /**
     * Listing-config payload keys that must not flow back to the agent — internal
     * permission / audit columns plus visibility controls that have nothing to do
     * with the listing layout the agent cares about. Anything outside this list
     * passes through (M-4).
     */
    private const LISTING_CONFIG_BLOCKED = [
        'tstamp', 'protected', 'groups', 'guests',
        'chmod', 'cuser', 'cgroup', 'includeChmod',
        'singleSRC', 'imgSize',
    ];

    /**
     * Defense-in-depth: even if a sensitive field somehow ends up exposed via a
     * future allow-list addition, strip canonical credential / session / crypto-key
     * column names from the dca_schema output before the agent sees them.
     *
     * @param array<string, mixed> $decoded
     */
    protected function postProcessDecoded(array &$decoded, string $toolName): void
    {
        if ('dca_schema' === $toolName) {
            $fields = $decoded['fields'] ?? null;
            if (\is_array($fields)) {
                foreach (self::SENSITIVE_FIELD_NAMES as $name) {
                    unset($fields[$name]);
                }
                $decoded['fields'] = $fields;
            }
            return;
        }

        if ('listing_config' === $toolName) {
            // M-4: tl_module->row() returns the complete row including internal
            // ACL/audit columns. Strip the ones that aren't relevant for listing
            // configuration so the agent sees a focused payload.
            foreach (self::LISTING_CONFIG_BLOCKED as $key) {
                unset($decoded[$key]);
            }
            return;
        }
    }

    protected function assertAccess(string $toolName): void
    {
        // Meta tools are read-only. We still require an authenticated backend user.
        if (!$this->tokenChecker->hasBackendUser()) {
            throw new ToolAccessDeniedException('Keine aktive Backend-Session.');
        }
    }
}
