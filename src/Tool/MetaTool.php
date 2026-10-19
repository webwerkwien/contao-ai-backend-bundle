<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiCoreBundle\Command\DcaOptionsCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\DcaPaletteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\DcaSchemaCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ListingConfigCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\SearchQueryCommand;

#[AsTool('dca_schema',     'Read the DCA field definitions of a Contao table the current user has module access to. The table name MUST come from the explicit per-user list provided in the system prompt — do not pass any other table name.', method: 'dcaSchema')]
#[AsTool('dca_palette',    'Which fields a record of this kind has, and which of them are mandatory: the palette Contao builds for the record, as the back end would show the form. Pass the values that choose the palette in `record`, e.g. {"type": "text"} for a content element. Ask this before writing fields you have not seen on such a record. Same table list as dca_schema.', method: 'dcaPalette')]
#[AsTool('dca_options',    'The values a field accepts, resolved on this installation (element types, page types, modules, templates …). Pass what the options depend on in `record`, e.g. {"ptable": "tl_news"}. Ask this instead of guessing a value. Same table list as dca_schema.', method: 'dcaOptions')]
#[AsTool('listing_config', 'Read the configuration of a Contao listing module (which fields are shown, sorting, filters). Only the user with the page module may use this — refuse otherwise.', method: 'listingConfig')]
#[AsTool('search_query',   'Run a Contao search query across the indexed content (full-text). Only the user with the page module may use this.', method: 'searchQuery')]
class MetaTool extends AbstractCoreCommandTool
{
    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
        private readonly DcaSchemaCommand $dcaCommand,
        private readonly ListingConfigCommand $listingCommand,
        private readonly SearchQueryCommand $searchCommand,
        private readonly ?DcaPaletteCommand $paletteCommand = null,
        private readonly ?DcaOptionsCommand $optionsCommand = null,
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
        'tl_faq', 'tl_faq_category',
        'tl_files',
        'tl_image_size', 'tl_image_size_item',
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
    #[AiContract(writes: false, trace: [])]
    public function dcaSchema(string $table): string
    {
        if (!\in_array($table, self::ALLOWED_DCA_TABLES, true)) {
            throw new ToolAccessDeniedException(
                \sprintf('Tabelle "%s" ist für dca_schema nicht freigegeben.', $table)
            );
        }

        // L-1: Die Werkzeug-Beschreibung sagt "a table the current user has
        // module access to" — bis 2026-09-02 stand dem nichts im Code gegenüber.
        $this->assertModuleAccessForTable($table);

        return $this->runCommand($this->dcaCommand, ['table' => $table], 'dca_schema');
    }

    /**
     * The palette of a record (v0.16.0: "agents should not guess", Michael 2026-10-12).
     * Same table allow-list and module check as dca_schema; the field names that must
     * never reach the agent are filtered out of the answer.
     *
     * @param string                    $table  Contao table name, e.g. tl_content
     * @param array<string,scalar|null>|string  $record Values that choose the palette, e.g. {"type": "text"}
     */
    #[AiContract(writes: false, trace: [])]
    public function dcaPalette(string $table, array|string $record = []): string
    {
        $record = self::payload($record, 'record');
        $this->assertDcaTable($table, 'dca_palette');

        return $this->runCommand($this->requireCommand($this->paletteCommand, 'dca_palette'), ['table' => $table, '--set' => self::recordOptions($record)], 'dca_palette');
    }

    /**
     * The values a field accepts. Not for the fields that must never reach the agent.
     *
     * @param string                    $table  Contao table name, e.g. tl_content
     * @param string                    $field  Field name, e.g. type
     * @param array<string,scalar|null>|string  $record Values the options depend on, e.g. {"ptable": "tl_news"}
     */
    #[AiContract(writes: false, trace: [])]
    public function dcaOptions(string $table, string $field, array|string $record = []): string
    {
        $record = self::payload($record, 'record');
        $this->assertDcaTable($table, 'dca_options');

        if (\in_array($field, self::SENSITIVE_FIELD_NAMES, true)) {
            throw new ToolAccessDeniedException(\sprintf('Feld "%s" ist für dca_options nicht freigegeben.', $field));
        }

        return $this->runCommand($this->requireCommand($this->optionsCommand, 'dca_options'), ['table' => $table, 'field' => $field, '--set' => self::recordOptions($record)], 'dca_options');
    }

    private function assertDcaTable(string $table, string $toolName): void
    {
        if (!\in_array($table, self::ALLOWED_DCA_TABLES, true)) {
            throw new ToolAccessDeniedException(\sprintf('Tabelle "%s" ist für %s nicht freigegeben.', $table, $toolName));
        }

        $this->assertModuleAccessForTable($table);
    }

    /**
     * @template T of object
     *
     * @param T|null $command
     *
     * @return T
     */
    private function requireCommand(?object $command, string $toolName): object
    {
        // The core bundle has the command since v0.16.0; the backend allows older cores.
        return $command ?? throw new ToolRefusedException(\sprintf('%s braucht contao-ai-core-bundle v0.16.0 oder neuer.', $toolName));
    }

    /**
     * The record as `--set key=value` pairs. Read-only, so no write allow-list; scalar
     * values only, without control characters, at most 20.
     *
     * @param array<mixed> $record
     *
     * @return list<string>
     */
    private static function recordOptions(array $record): array
    {
        $out = [];

        foreach (self::normalizeFieldsPayload($record) as $key => $value) {
            if (null === $value || !\is_scalar($value) || !\is_string($key) || 1 !== preg_match('/^[A-Za-z0-9_]+$/', $key)) {
                continue;
            }

            $value = (string) $value;

            if (1 === preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new ToolRefusedException(\sprintf('Feld "%s" enthält ungültige Steuerzeichen.', $key));
            }

            $out[] = $key . '=' . $value;
        }

        return \array_slice($out, 0, 20);
    }

    /**
     * @param int $moduleId Module ID (tl_module)
     */
    #[AiContract(writes: false, trace: [])]
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
    #[AiContract(writes: false, trace: [])]
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

        if ('dca_palette' === $toolName) {
            foreach (['fields', 'mandatory'] as $key) {
                if (\is_array($decoded[$key] ?? null)) {
                    $decoded[$key] = array_values(array_diff($decoded[$key], self::SENSITIVE_FIELD_NAMES));
                }
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
        // All meta-tools require an authenticated backend session.
        if (!$this->tokenChecker->hasBackendUser()) {
            throw new ToolAccessDeniedException('Keine aktive Backend-Session.');
        }
        // Module-gated meta-tools (search_query, listing_config) are also
        // filtered out of the schema sent to the LLM via ToolAccessChecker
        // (TOOL_MAP entry). Run the runtime check here as defense in depth so
        // a direct `Toolbox::execute(new ToolCall('search_query', …))` from
        // somewhere unexpected still fails closed for non-page users.
        if (\in_array($toolName, ['search_query', 'listing_config'], true)) {
            $user = $this->getCurrentBackendUser();
            if (null === $user) {
                throw new ToolAccessDeniedException('Keine aktive Backend-Session.');
            }
            $this->accessChecker->assertCanUseTool($user, $toolName);
        }
    }
}
