<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Security;

use Contao\ArticleModel;
use Contao\BackendUser;
use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\FaqCategoryModel;
use Contao\FaqModel;
use Contao\NewsArchiveModel;
use Contao\NewsModel;
use Contao\PageModel;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;

/**
 * Phase 9.5: zentraler Voter-Helper für die Macro-Tools (record_clone /
 * record_rewrite). Bündelt die Per-Record-Voter-Patterns, die in den
 * Single-Record-Tools (NewsTool, PageTool, ArticleTool, ContentTool) seit
 * Phase 5 H-9 schon existieren — Macro-Tools rufen die hier statt eigener
 * Code-Duplikate.
 *
 * Tabellen-Matrix:
 *   tl_news_archive    → isGranted('contao_user.news',      [$id])
 *   tl_calendar        → isGranted('contao_user.calendars', [$id])
 *   tl_faq_category    → isGranted('contao_user.faqs',      [$id])
 *
 * 🔴 Bis 2026-09-02 stand hier `hasAccess($id, 'calendar')` bzw. `'faq'` —
 * im Singular. `hasAccess($feld, $array)` liest `$this->$array` am Benutzer,
 * und die Eigenschaften heißen `calendars` und `faqs`: die DCA von Contao sagt
 * `'userRoot' => 'calendars'`. `$this->calendar` gibt es nicht, also fiel die
 * Prüfung durch und **jeder Nicht-Admin wurde abgewiesen**, auch mit legitimen
 * Rechten. Es scheiterte geschlossen, war also kein Loch — aber Kalender und
 * FAQ waren für Redakteure schlicht unbenutzbar.
 *
 * Gleich mit erledigt: `hasAccess()` ist seit Contao 5.2 deprecated und laut
 * eigener Meldung *"will no longer work in Contao 6"*. Der Voter-Weg ist der,
 * den `RecordListTool` ohnehin schon ging — und dort mit den richtigen
 * Feldnamen.
 *   tl_news            → archive-access via news.pid
 *   tl_calendar_events → calendar-access via event.pid
 *   tl_faq             → faq-access via faq.pid
 *   tl_page            → USER_CAN_EDIT_PAGE / _DELETE_PAGE auf row
 *   tl_article         → USER_CAN_EDIT_ARTICLES auf parent-page
 *   tl_content         → USER_CAN_EDIT_ARTICLES auf parent-article→page
 *
 * Container-Anlage (für record_clone):
 *   tl_news_archive    → Voter `contao_user.cud.tl_news_archive::create`
 *   tl_calendar        → Voter `contao_user.cud.tl_calendar::create`
 *   tl_faq_category    → Voter `contao_user.cud.tl_faq_category::create`
 *   tl_page            → USER_CAN_EDIT_PAGE_HIERARCHY auf source.pid
 *
 * **Hinweis:** Contao 5.7 (FieldPermissionMigration Version507) hat die
 * alten `newp`/`calp`/`faqp`-Spalten zugunsten einer zentralen `cud`-Spalte
 * abgeschafft (Format `tablename::operation`). Wir checken via Voter statt
 * Direkt-Zugriff, dann passt's für 5.7+ und ist forward-kompatibel.
 */
class RecordPermissionChecker
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * Hard-assert version: wirft ToolAccessDeniedException bei Verbot.
     * Verwendet von den Macro-Tools für die Source-Record-Prüfung.
     *
     * @param string $operation 'read' | 'edit' | 'delete'
     * @throws ToolAccessDeniedException
     * @throws ToolExecutionException
     */
    public function assertRecordAccess(BackendUser $user, string $table, int $id, string $operation): void
    {
        if ($user->isAdmin) {
            return;
        }
        $denial = $this->checkRecordAccess($user, $table, $id, $operation);
        if (null !== $denial) {
            throw new ToolAccessDeniedException($denial);
        }
    }

    /**
     * Soft-check Variante für recursive-Pfade. Liefert null bei Erlaubnis,
     * sonst eine kurze Begründung — die Caller stecken die ID + Reason dann
     * in den `refused`-Array statt hart abzubrechen.
     */
    public function recordAccessDenialReason(BackendUser $user, string $table, int $id, string $operation): ?string
    {
        if ($user->isAdmin) {
            return null;
        }
        return $this->checkRecordAccess($user, $table, $id, $operation);
    }

    /**
     * @throws ToolAccessDeniedException
     * @throws ToolExecutionException
     */
    public function assertCanCreateContainer(BackendUser $user, string $sourceTable, int $sourceId): void
    {
        if ($user->isAdmin) {
            return;
        }

        switch ($sourceTable) {
            case 'tl_news_archive':
                $this->assertContainerCreate($user, 'tl_news_archive', 'newp');
                return;
            case 'tl_calendar':
                $this->assertContainerCreate($user, 'tl_calendar', 'calp');
                return;
            case 'tl_faq_category':
                $this->assertContainerCreate($user, 'tl_faq_category', 'faqp');
                return;
            case 'tl_page':
                $this->assertCanCreatePageBelow($user, $sourceId);
                return;
            default:
                throw new ToolAccessDeniedException(\sprintf(
                    'Container-Anlage für "%s" wird vom Permission-Checker nicht unterstützt.',
                    $sourceTable
                ));
        }
    }

    private function checkRecordAccess(BackendUser $user, string $table, int $id, string $operation): ?string
    {
        $this->framework->initialize();

        return match ($table) {
            'tl_news_archive'    => $this->checkNewsArchiveAccess($user, $id),
            'tl_news'            => $this->checkNewsAccess($user, $id),
            'tl_calendar'        => $this->checkCalendarAccess($user, $id),
            'tl_calendar_events' => $this->checkCalendarEventAccess($user, $id),
            'tl_faq_category'    => $this->checkFaqCategoryAccess($user, $id),
            'tl_faq'             => $this->checkFaqAccess($user, $id),
            'tl_page'            => $this->checkPageAccess($user, $id, $operation),
            'tl_article'         => $this->checkArticleAccess($user, $id),
            'tl_content'         => $this->checkContentAccess($user, $id),
            default              => \sprintf('Tabelle "%s" wird nicht unterstützt.', $table),
        };
    }

    private function checkNewsArchiveAccess(BackendUser $user, int $id): ?string
    {
        if (!class_exists(NewsArchiveModel::class)) {
            return 'contao/news-bundle ist nicht installiert.';
        }
        if (!\in_array('news', (array) ($user->modules ?? []), true)) {
            return 'Backend-Modul "news" fehlt.';
        }
        if (!$this->authorizationChecker->isGranted('contao_user.news', [$id])) {
            return \sprintf('Kein Zugriff auf News-Archiv %d.', $id);
        }
        return null;
    }

    private function checkNewsAccess(BackendUser $user, int $id): ?string
    {
        if (!class_exists(NewsModel::class)) {
            return 'contao/news-bundle ist nicht installiert.';
        }
        if (!\in_array('news', (array) ($user->modules ?? []), true)) {
            return 'Backend-Modul "news" fehlt.';
        }
        $news = NewsModel::findById($id);
        if (null === $news) {
            return \sprintf('News-Eintrag %d nicht gefunden.', $id);
        }
        if (!$this->authorizationChecker->isGranted('contao_user.news', [(int) $news->pid])) {
            return \sprintf('Kein Zugriff auf News-Archiv %d.', (int) $news->pid);
        }
        return null;
    }

    private function checkCalendarAccess(BackendUser $user, int $id): ?string
    {
        if (!class_exists(CalendarModel::class)) {
            return 'contao/calendar-bundle ist nicht installiert.';
        }
        if (!\in_array('calendar', (array) ($user->modules ?? []), true)) {
            return 'Backend-Modul "calendar" fehlt.';
        }
        if (!$this->authorizationChecker->isGranted('contao_user.calendars', [$id])) {
            return \sprintf('Kein Zugriff auf Kalender %d.', $id);
        }
        return null;
    }

    private function checkCalendarEventAccess(BackendUser $user, int $id): ?string
    {
        if (!class_exists(CalendarEventsModel::class)) {
            return 'contao/calendar-bundle ist nicht installiert.';
        }
        if (!\in_array('calendar', (array) ($user->modules ?? []), true)) {
            return 'Backend-Modul "calendar" fehlt.';
        }
        $event = CalendarEventsModel::findById($id);
        if (null === $event) {
            return \sprintf('Kalender-Eintrag %d nicht gefunden.', $id);
        }
        if (!$this->authorizationChecker->isGranted('contao_user.calendars', [(int) $event->pid])) {
            return \sprintf('Kein Zugriff auf Kalender %d.', (int) $event->pid);
        }
        return null;
    }

    private function checkFaqCategoryAccess(BackendUser $user, int $id): ?string
    {
        if (!class_exists(FaqCategoryModel::class)) {
            return 'contao/faq-bundle ist nicht installiert.';
        }
        if (!\in_array('faq', (array) ($user->modules ?? []), true)) {
            return 'Backend-Modul "faq" fehlt.';
        }
        if (!$this->authorizationChecker->isGranted('contao_user.faqs', [$id])) {
            return \sprintf('Kein Zugriff auf FAQ-Kategorie %d.', $id);
        }
        return null;
    }

    private function checkFaqAccess(BackendUser $user, int $id): ?string
    {
        if (!class_exists(FaqModel::class)) {
            return 'contao/faq-bundle ist nicht installiert.';
        }
        if (!\in_array('faq', (array) ($user->modules ?? []), true)) {
            return 'Backend-Modul "faq" fehlt.';
        }
        $faq = FaqModel::findById($id);
        if (null === $faq) {
            return \sprintf('FAQ-Eintrag %d nicht gefunden.', $id);
        }
        if (!$this->authorizationChecker->isGranted('contao_user.faqs', [(int) $faq->pid])) {
            return \sprintf('Kein Zugriff auf FAQ-Kategorie %d.', (int) $faq->pid);
        }
        return null;
    }

    private function checkPageAccess(BackendUser $user, int $id, string $operation): ?string
    {
        if (!\in_array('page', (array) ($user->modules ?? []), true)) {
            return 'Backend-Modul "page" fehlt.';
        }
        $page = PageModel::findById($id);
        if (null === $page) {
            return \sprintf('Seite %d nicht gefunden.', $id);
        }
        $permission = match ($operation) {
            'delete' => ContaoCorePermissions::USER_CAN_DELETE_PAGE,
            default  => ContaoCorePermissions::USER_CAN_EDIT_PAGE,
        };
        if (!$this->authorizationChecker->isGranted($permission, $page->row())) {
            return \sprintf('Kein Zugriff auf Seite %d.', $id);
        }
        return null;
    }

    private function checkArticleAccess(BackendUser $user, int $id): ?string
    {
        if (!\in_array('article', (array) ($user->modules ?? []), true)) {
            return 'Backend-Modul "article" fehlt.';
        }
        $article = ArticleModel::findById($id);
        if (null === $article) {
            return \sprintf('Artikel %d nicht gefunden.', $id);
        }
        $page = PageModel::findById((int) $article->pid);
        if (null === $page) {
            return \sprintf('Übergeordnete Seite zu Artikel %d nicht gefunden.', $id);
        }
        if (!$this->authorizationChecker->isGranted(ContaoCorePermissions::USER_CAN_EDIT_ARTICLES, $page->row())) {
            return \sprintf('Kein Zugriff auf Artikel %d.', $id);
        }
        return null;
    }

    private function checkContentAccess(BackendUser $user, int $id): ?string
    {
        if (!\in_array('article', (array) ($user->modules ?? []), true)) {
            return 'Backend-Modul "article" fehlt.';
        }
        $content = ContentModel::findById($id);
        if (null === $content) {
            return \sprintf('Inhaltselement %d nicht gefunden.', $id);
        }
        $ptable = (string) ($content->ptable ?? 'tl_article');
        $pid    = (int) $content->pid;
        // Nested content (ptable=tl_content): rekursiv bis zum Article auflösen.
        $guard = 0;
        while ('tl_content' === $ptable && $guard++ < 10) {
            $parent = ContentModel::findById($pid);
            if (null === $parent) {
                return \sprintf('Eltern-Inhaltselement %d nicht gefunden.', $pid);
            }
            $ptable = (string) ($parent->ptable ?? 'tl_article');
            $pid    = (int) $parent->pid;
        }
        if ('tl_article' !== $ptable) {
            return \sprintf('Inhaltselemente in "%s" können nur von Admins verwaltet werden.', $ptable);
        }
        $article = ArticleModel::findById($pid);
        if (null === $article) {
            return \sprintf('Artikel %d nicht gefunden.', $pid);
        }
        $page = PageModel::findById((int) $article->pid);
        if (null === $page) {
            return \sprintf('Übergeordnete Seite zu Artikel %d nicht gefunden.', $pid);
        }
        if (!$this->authorizationChecker->isGranted(ContaoCorePermissions::USER_CAN_EDIT_ARTICLES, $page->row())) {
            return \sprintf('Kein Zugriff auf Artikel %d für Inhaltselement-Operationen.', $pid);
        }
        return null;
    }

    /**
     * Pfad (a) — Container-Anlage-Permission. Dual-Path für Contao 5.3–5.6
     * (legacy `newp`/`calp`/`faqp`-Arrays) und 5.7+ (zentrale `cud`-Liste,
     * via Voter `contao_user.cud.<table>::create`).
     *
     * Strategie: erst Voter probieren — der existiert in 5.7+ und liefert
     * authoritative Ja/Nein. In 5.3–5.6 ist der Voter unbekannt → Symfony
     * AccessDecisionManager stimmt dort default-deny → wir fallen durch
     * auf das alte Array-Feld. So funktioniert beides ohne Versions-Sniffing.
     */
    private function assertContainerCreate(BackendUser $user, string $sourceTable, string $legacyPermField): void
    {
        $subject = 'contao_user.cud.'.$sourceTable.'::create';
        if ($this->authorizationChecker->isGranted($subject)) {
            return;
        }
        // Legacy-Fallback (Contao 5.3–5.6): newp/calp/faqp existieren als
        // serialisierte Arrays auf tl_user. Auf 5.7+ wurden diese Spalten
        // per Migration entfernt — dort ist $user->{$legacyPermField} null
        // und der Check schlägt sauber fehl.
        $perms = (array) ($user->{$legacyPermField} ?? []);
        if (\in_array('create', $perms, true)) {
            return;
        }
        throw new ToolAccessDeniedException(\sprintf(
            'Kein Anlage-Recht für %s (weder cud "%s::create" noch %s enthält "create").',
            $sourceTable,
            $sourceTable,
            $legacyPermField
        ));
    }

    /**
     * Pfad (c) — Klon einer Seite landet als Geschwister-Seite unter source.pid.
     * Editor braucht USER_CAN_EDIT_PAGE_HIERARCHY auf dem Eltern-Knoten. Root
     * (pid=0) ist Admin-Territorium.
     */
    private function assertCanCreatePageBelow(BackendUser $user, int $sourceId): void
    {
        $this->framework->initialize();
        $source = PageModel::findById($sourceId);
        if (null === $source) {
            throw new ToolRefusedException(\sprintf('Quell-Seite %d nicht gefunden.', $sourceId));
        }
        $parentId = (int) $source->pid;
        if (0 === $parentId) {
            throw new ToolAccessDeniedException(
                'Root-Seiten anlegen ist nur für Admins erlaubt.'
            );
        }
        $parent = PageModel::findById($parentId);
        if (null === $parent) {
            throw new ToolRefusedException(\sprintf('Eltern-Seite %d nicht gefunden.', $parentId));
        }
        if (!$this->authorizationChecker->isGranted(
            ContaoCorePermissions::USER_CAN_EDIT_PAGE_HIERARCHY,
            $parent->row()
        )) {
            throw new ToolAccessDeniedException(\sprintf(
                'Kein Recht, Subseiten unter Seite %d anzulegen.',
                $parentId
            ));
        }
    }
}
