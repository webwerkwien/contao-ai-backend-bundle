<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\PageModel;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\RecordPermissionChecker;
use Webwerkwien\ContaoAiBackendBundle\Security\TableAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\ConsoleRunner;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;

/**
 * Translation links of terminal42/contao-changelanguage in the chat (v0.13.0).
 *
 * Runs core-bundle v1.4.0's contao:language:link and contao:language:check, which
 * exist only where the package is installed — so this tool is offered only there. The
 * core checks the target against what the back end offers and writes through its
 * record writer; this tool adds what depends on the user, as the back end does: the
 * module of the table, the record (page mounts, archives), and the field permission —
 * languageMain and master are excluded fields, an editor needs them under
 * "allowed fields".
 */
#[AsTool(
    'language_link',
    'Links a record to its translation in the main language (changelanguage): pages, articles, news, events and FAQ '
    .'to their counterpart, archives (tl_news_archive, tl_calendar, tl_faq_category) to their master archive. '
    .'Link the page before its articles and the archive before its entries. main = 0 removes the link.',
    method: 'link',
)]
#[AsTool(
    'language_check',
    'Reports missing or broken translation links per website root of another language: unlinked pages, links into the wrong tree, '
    .'duplicates, unlinked articles, archives without master and their unlinked entries. Reads only.',
    method: 'check',
)]
class LanguageTool extends AbstractCoreCommandTool
{
    private const TABLES = [
        'tl_page', 'tl_article',
        'tl_news', 'tl_news_archive',
        'tl_calendar_events', 'tl_calendar',
        'tl_faq', 'tl_faq_category',
    ];

    private const ARCHIVES = ['tl_news_archive', 'tl_calendar', 'tl_faq_category'];

    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
        private readonly ConsoleRunner $console,
        private readonly TableAccess $tables,
        private readonly RecordPermissionChecker $records,
    ) {
        parent::__construct($accessChecker, $tokenChecker);
    }

    public function getToolName(): string
    {
        return 'language_link';
    }

    /**
     * Only where changelanguage is installed: the core registers the commands then.
     */
    public function isAccessibleBy(BackendUser $user): bool
    {
        return class_exists(\Terminal42\ChangeLanguage\PageFinder::class) && $this->console->has('contao:language:link');
    }

    #[AiContract(writes: true, trace: ['tl_version', 'tl_log'], traceWhen: 'on-success', repeatable: true)]
    public function link(string $table, int $id, int $main): string
    {
        $this->assertAccess('language_link');
        $user = $this->requireBackendUser();

        if (!\in_array($table, self::TABLES, true)) {
            throw new ToolRefusedException(\sprintf('Translation links exist for %s, not for "%s".', implode(', ', self::TABLES), $table));
        }

        if (!$this->tables->canAccess($user, $table)) {
            throw new ToolAccessDeniedException(\sprintf('Kein Zugriff auf ein Backend-Modul für "%s".', $table));
        }

        $this->records->assertRecordAccess($user, $table, $id, 'edit');

        $field = $this->field($table, $id);

        if (!$user->isAdmin && !$user->hasAccess($table . '::' . $field, 'alexf')) {
            throw new ToolAccessDeniedException(\sprintf('Das Feld %s.%s ist für diesen Benutzer nicht freigegeben (Erlaubte Felder).', $table, $field));
        }

        [$code, $output] = $this->console->run([
            'command'    => 'contao:language:link',
            '--table'    => $table,
            '--id'       => (string) $id,
            '--main'     => (string) $main,
            '--operator' => (string) ($user->username ?? ''),
        ]);

        return $this->answer($code, $output, 'language_link');
    }

    #[AiContract(writes: false, trace: [])]
    public function check(int $root = 0): string
    {
        $this->assertAccess('language_check');
        $user = $this->requireBackendUser();

        if (!$this->tables->canAccess($user, 'tl_page')) {
            throw new ToolAccessDeniedException('Kein Zugriff auf die Seitenstruktur.');
        }

        $input = ['command' => 'contao:language:check'];

        if ($root > 0) {
            $input['--root'] = (string) $root;
        }

        [$code, $output] = $this->console->run($input);

        return $this->answer($code, $output, 'language_check');
    }

    /**
     * The field the core will write — a copy of `ChangeLanguageLinks::field()` in
     * core-bundle v1.4.0, kept on purpose: this bundle allows older cores and is
     * developed against one, so it cannot call the class (review K8). Change both.
     */
    private function field(string $table, int $id): string
    {
        if (\in_array($table, self::ARCHIVES, true)) {
            return 'master';
        }

        if ('tl_page' === $table && 'root' === PageModel::findById($id)?->type) {
            return 'languageRoot';
        }

        return 'languageMain';
    }
}
