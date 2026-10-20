<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\TableAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\ConsoleRunner;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;

/**
 * The linked language versions of a record (v0.21.0), through core v1.11.0's
 * contao:language:versions — offered only where the core has it, which is only where
 * terminal42/contao-changelanguage is installed.
 *
 * A chat on showcase changed the English slider headline and told the user the page
 * existed in English only; the linked German page had the same slider. Asked to take the
 * login article offline, it never mentioned the German one. It had nothing to look it up
 * with (2026-10-20). This is that lookup; content_find names the versions of its hits.
 *
 * Versions and roots the user may not read are left out and counted, as in content_find.
 */
#[AsTool(
    'language_versions',
    'The other language versions of a page, article, news entry, event or FAQ, as the language switcher links them: '
    .'each with id, title, published, root and language. When none is linked, otherRoots lists the website roots of other '
    .'languages — a version may exist there without a link, so never say there is no other language. Reads only.',
    method: 'versions',
)]
class LanguageVersionsTool extends AbstractCoreCommandTool
{
    use FiltersLanguageVersions;

    private const TABLES = ['tl_page', 'tl_article', 'tl_news', 'tl_calendar_events', 'tl_faq'];

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
        return 'language_versions';
    }

    /**
     * Only where the core has the command (v1.11.0, registered with changelanguage).
     */
    public function isAccessibleBy(BackendUser $user): bool
    {
        return $this->console->has('contao:language:versions') && parent::isAccessibleBy($user);
    }

    /**
     * @param string $table tl_page, tl_article, tl_news, tl_calendar_events or tl_faq
     * @param int    $id    The record id
     */
    #[AiContract(writes: false, trace: [])]
    public function versions(string $table, int $id): string
    {
        $this->assertAccess('language_versions');
        $user = $this->requireBackendUser();

        if (!\in_array($table, self::TABLES, true)) {
            throw new ToolRefusedException(\sprintf('Language versions exist for %s, not for "%s".', implode(', ', self::TABLES), $table));
        }
        if (!$this->tables->canAccess($user, $table)) {
            throw new ToolAccessDeniedException(\sprintf('Kein Zugriff auf ein Backend-Modul für "%s".', $table));
        }
        $record = $this->dcAccess->fetchRecord($table, $id);
        if (null !== $record && !$this->dcAccess->isGranted(new ReadAction($table, $record))) {
            throw new ToolAccessDeniedException(\sprintf('Kein Lesezugriff auf %s:%d.', $table, $id));
        }

        [$code, $output] = $this->console->run(['command' => 'contao:language:versions', 'table' => $table, 'id' => (string) $id]);

        $decoded = json_decode(trim($output), true);
        if (0 !== $code || !\is_array($decoded) || 'ok' !== ($decoded['status'] ?? null)) {
            return $this->answer($code, $output, 'language_versions');
        }
        // Fail closed: nothing unfiltered goes to the model.
        if (!\is_array($decoded['versions'] ?? null) || !\is_array($decoded['otherRoots'] ?? null)) {
            throw new ToolExecutionException('Tool "language_versions": contao:language:versions answered without versions or otherRoots.');
        }

        [$decoded['versions'], $hiddenVersions] = $this->readable($table, $decoded['versions']);
        [$decoded['otherRoots'], $hiddenRoots] = $this->readable('tl_page', $decoded['otherRoots']);
        if ($hiddenVersions + $hiddenRoots > 0) {
            $decoded['hidden'] = \sprintf('%d version(s) and %d website root(s) are outside what this user may read.', $hiddenVersions, $hiddenRoots);
        }

        return $this->answer(0, (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'language_versions');
    }
}
