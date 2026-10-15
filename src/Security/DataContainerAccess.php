<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Security;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Strategy\PriorityStrategy;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;

/**
 * Record rights as the back end decides them (v0.18.0): the data container voters
 * behind `isGranted('contao_dc.<table>', <action>)`, asked with the same action
 * `DC_Table` builds — create (`new CreateAction($table, $this->set)`), save
 * (`new UpdateAction($table, $currentRecord, $arrValues)`), delete and show.
 *
 * Until v0.18.0 every tool rebuilt a part of this by hand: module plus news archive or
 * page mount. The voters also decide page and element types, the page hierarchy, error
 * pages below a root only, articles only on pages with a main column, element nesting,
 * the create and delete rights of the `cud` list, and whatever voters extensions add.
 *
 * Admins are asked too, as in the back end: they hold every right anyway, and the voters
 * still refuse structures Contao refuses (a root below another page, a closed table).
 *
 * Field rights stay with FieldPermission; the voters only check a table whose fields
 * are all excluded.
 */
class DataContainerAccess
{
    private const VERB = [
        'create' => 'anzulegen',
        'read'   => 'zu lesen',
        'update' => 'zu ändern',
        'delete' => 'zu löschen',
    ];

    private const LABEL = [
        'tl_page'            => 'Seite',
        'tl_article'         => 'Artikel',
        'tl_content'         => 'Inhaltselement',
        'tl_news'            => 'News',
        'tl_news_archive'    => 'News-Archiv',
        'tl_calendar'        => 'Kalender',
        'tl_calendar_events' => 'Event',
        'tl_faq'             => 'FAQ',
        'tl_faq_category'    => 'FAQ-Kategorie',
        'tl_form'               => 'Formular',
        'tl_newsletter_channel' => 'Newsletter-Kanal',
        'tl_module'             => 'Modul',
        'tl_layout'             => 'Layout',
    ];

    private const NEW_OBJECT = [
        'tl_page'            => 'eine Seite',
        'tl_article'         => 'einen Artikel',
        'tl_content'         => 'ein Inhaltselement',
        'tl_news'            => 'eine News',
        'tl_news_archive'    => 'ein News-Archiv',
        'tl_calendar'        => 'einen Kalender',
        'tl_calendar_events' => 'ein Event',
        'tl_faq'             => 'eine FAQ',
        'tl_faq_category'    => 'eine FAQ-Kategorie',
        'tl_form'               => 'ein Formular',
        'tl_newsletter_channel' => 'einen Newsletter-Kanal',
        'tl_module'             => 'ein Modul',
        'tl_layout'             => 'ein Layout',
    ];

    private const RIGHTS = [
        'tl_page'            => 'Module, Seitenfreigaben (Pagemounts), Seitenrechte und erlaubte Seitentypen',
        'tl_article'         => 'Module, Seitenfreigaben (Pagemounts) und Artikelrechte',
        'tl_content'         => 'Module, Rechte auf den übergeordneten Artikel, die News oder das Event und erlaubte Elementtypen',
        'tl_news'            => 'das Modul News und die erlaubten News-Archive',
        'tl_news_archive'    => 'das Modul News, die erlaubten News-Archive und die Archivrechte (anlegen, löschen)',
        'tl_calendar'        => 'das Modul Events, die erlaubten Kalender und die Kalenderrechte (anlegen, löschen)',
        'tl_calendar_events' => 'das Modul Events und die erlaubten Kalender',
        'tl_faq'             => 'das Modul FAQ und die erlaubten FAQ-Kategorien',
        'tl_faq_category'    => 'das Modul FAQ, die erlaubten FAQ-Kategorien und die Kategorierechte (anlegen, löschen)',
        'tl_form'               => 'das Modul Formulargenerator, die erlaubten Formulare und die Formularrechte (anlegen, löschen)',
        'tl_newsletter_channel' => 'das Modul Newsletter, die erlaubten Kanäle und die Kanalrechte (anlegen, löschen)',
        'tl_module'             => 'das Modul Themes, das Recht auf Frontend-Module und die erlaubten Modultypen',
        'tl_layout'             => 'das Modul Themes und das Recht auf Seitenlayouts',
    ];

    /**
     * Contao's decision for these questions: the registered voters, the first one that
     * does not abstain wins (`PriorityStrategy`).
     *
     * Not the global `isGranted()`. The data container voters only vote "denied" or
     * abstain, and `DefaultDataContainerVoter` grants last — which only works under the
     * priority strategy. Contao's `ContaoStrategy` applies it while the main request runs
     * in the `contao_backend` or `contao_frontend` firewall; everywhere else Symfony's
     * default (affirmative) strategy decides, where one "granted" outvotes every "denied"
     * and the default voter allows everything. The chat runs in the back-end firewall,
     * but the CLI bridge (`/_ai_cli/macro`, no `_scope`) runs in none: there the global
     * check allowed an error page below a regular page and a root anywhere for an editor
     * (measured on c5 2026-10-14). Deciding here gives the back end's answer in every
     * context. The voters' own sub-questions (module, page rights) each have one voter,
     * which answers them the same under either strategy.
     */
    private readonly AccessDecisionManager $decisionManager;

    /**
     * @param iterable<VoterInterface> $voters
     */
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly Connection $connection,
        #[AutowireIterator('security.voter')]
        iterable $voters,
    ) {
        $this->decisionManager = new AccessDecisionManager($voters, new PriorityStrategy());
    }

    /**
     * The voters' answer. The DCA is loaded first: `TableAccessVoter` reads the fields
     * from `$GLOBALS['TL_DCA']` without loading it; without them it treats every field as
     * excluded and refuses an editor who lacks the right on all fields of the table (its
     * own comment says "abstains", the code denies — Fable review 2026-10-14).
     * Without a signed-in user nothing is granted.
     */
    public function isGranted(CreateAction|ReadAction|UpdateAction|DeleteAction $action): bool
    {
        $token = $this->tokenStorage->getToken();
        if (null === $token) {
            return false;
        }

        $this->framework->initialize();
        $this->framework->getAdapter(Controller::class)->loadDataContainer($action->getDataSource());

        return $this->decisionManager->decide($token, [ContaoCorePermissions::DC_PREFIX . $action->getDataSource()], $action);
    }

    /**
     * The record as `DC_Table` hands it to the voters: the raw row.
     *
     * @return array<string, mixed>|null
     */
    public function fetchRecord(string $table, int $id): ?array
    {
        if (!preg_match('/^tl_[a-z0-9_]+$/', $table)) {
            throw new ToolAccessDeniedException(\sprintf('Tabelle "%s" wird nicht unterstützt.', $table));
        }

        try {
            $row = $this->connection->fetchAssociative('SELECT * FROM ' . $table . ' WHERE id = ?', [$id]);
        } catch (TableNotFoundException) {
            // tl_news without the news bundle, say: nothing there to find.
            return null;
        }

        return false === $row ? null : $row;
    }

    /**
     * Whether the table is there at all. News, events, FAQ and newsletters come with
     * optional bundles; without one its tables are missing, and "record 4 not found"
     * would send the agent looking for the wrong thing.
     */
    public function tableExists(string $table): bool
    {
        return $this->connection->createSchemaManager()->tablesExist([$table]);
    }

    /**
     * @return array<string, mixed> the record
     *
     * @throws ToolRefusedException      when it does not exist
     * @throws ToolAccessDeniedException when the voters refuse
     */
    public function assertRead(string $table, int $id): array
    {
        $current = $this->requireRecord($table, $id);
        $this->assert(new ReadAction($table, $current), $id);

        return $current;
    }

    /**
     * @param array<string, mixed>|null $new the values a write sets; null asks whether the
     *                                       record may be edited at all, like opening the form
     *
     * @return array<string, mixed> the record
     */
    public function assertUpdate(string $table, int $id, ?array $new = null): array
    {
        $current = $this->requireRecord($table, $id);
        $this->assert(new UpdateAction($table, $current, $new), $id);

        return $current;
    }

    /**
     * @return array<string, mixed> the record
     */
    public function assertDelete(string $table, int $id): array
    {
        $current = $this->requireRecord($table, $id);
        $this->assert(new DeleteAction($table, $current), $id);

        return $current;
    }

    /**
     * @param array<string, mixed> $new the new record as far as it is known: `pid`, a
     *                                  dynamic `ptable`, `sorting`, `type` and the values set
     */
    public function assertCreate(string $table, array $new): void
    {
        $this->assert(new CreateAction($table, $new), null);
    }

    /**
     * The reason a voter refused, or null — for paths that collect refusals instead of
     * stopping at the first one.
     */
    public function denialReason(CreateAction|ReadAction|UpdateAction|DeleteAction $action, ?int $id): ?string
    {
        return $this->isGranted($action) ? null : self::deniedMessage($action, $id);
    }

    /**
     * Parent tables whose content Contao's voters check — each only while the voter that
     * judges it is installed: articles (core), news and events (their bundles), each also
     * through nested elements. A `tl_news` table left behind by a removed news bundle
     * has nobody to judge it (Fable review 2026-10-14).
     */
    private const VOTED_CONTENT_PARENTS = [
        'tl_article'         => 'Contao\CoreBundle\Security\Voter\DataContainer\ArticleContentVoter',
        'tl_news'            => 'Contao\NewsBundle\Security\Voter\NewsContentVoter',
        'tl_calendar_events' => 'Contao\CalendarBundle\Security\Voter\CalendarContentVoter',
    ];

    private const MAX_NESTING = 10;

    /**
     * The content voters only judge the parent tables they know and let any other pass
     * ("not mine"). In the back end that is no gap: `ptable` comes from the module the
     * editor works in. In the chat the model sends it, so {"ptable": "tl_whatever"} would
     * leave only the element type checked. Editors get the parent tables Contao has a
     * rule for; callers let admins keep every one, as before v0.18.0.
     *
     * @param array<string, mixed> $record at least `ptable` and `pid`
     */
    public function contentParentDenial(array $record): ?string
    {
        $ptable = (string) (($record['ptable'] ?? '') ?: 'tl_article');
        $pid = (int) ($record['pid'] ?? 0);
        for ($depth = 0; 'tl_content' === $ptable; ++$depth) {
            if ($depth >= self::MAX_NESTING) {
                return \sprintf('Inhaltselemente sind tiefer als %d Ebenen verschachtelt; der Chat bearbeitet sie nicht.', self::MAX_NESTING);
            }
            $parent = $this->fetchRecord('tl_content', $pid);
            if (null === $parent) {
                return \sprintf('Eltern-Inhaltselement %d nicht gefunden.', $pid);
            }
            $ptable = (string) (($parent['ptable'] ?? '') ?: 'tl_article');
            $pid = (int) $parent['pid'];
        }

        $voter = self::VOTED_CONTENT_PARENTS[$ptable] ?? null;
        if (null !== $voter && class_exists($voter)) {
            return null;
        }

        return \sprintf(
            'Inhaltselemente in "%s" verwaltet im Chat nur ein Admin: Für Redakteure stützt sich der Chat nur auf Contaos Prüfung von Artikeln, News und Events.',
            $ptable,
        );
    }

    public static function deniedMessage(CreateAction|ReadAction|UpdateAction|DeleteAction $action, ?int $id): string
    {
        $table = $action->getDataSource();
        $operation = match (true) {
            $action instanceof CreateAction => 'create',
            $action instanceof ReadAction   => 'read',
            $action instanceof UpdateAction => 'update',
            $action instanceof DeleteAction => 'delete',
        };
        $object = null === $id
            ? 'an dieser Stelle ' . (self::NEW_OBJECT[$table] ?? 'einen Datensatz')
            : \sprintf('%s %d', self::LABEL[$table] ?? 'Datensatz', $id);

        return \sprintf(
            'Contao erlaubt Ihnen nicht, %s %s. Maßgeblich sind die Rechte Ihrer Benutzergruppe: %s.',
            $object,
            self::VERB[$operation],
            self::RIGHTS[$table] ?? 'Module und Tabellenrechte',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function requireRecord(string $table, int $id): array
    {
        $current = $this->fetchRecord($table, $id);
        if (null === $current) {
            throw new ToolRefusedException(\sprintf('%s %d nicht gefunden.', self::LABEL[$table] ?? 'Datensatz', $id));
        }

        return $current;
    }

    private function assert(CreateAction|ReadAction|UpdateAction|DeleteAction $action, ?int $id): void
    {
        if (!$this->isGranted($action)) {
            throw new ToolAccessDeniedException(self::deniedMessage($action, $id));
        }
    }
}
