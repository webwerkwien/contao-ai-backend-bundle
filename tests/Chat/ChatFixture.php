<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Chat;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoAiCoreBundle\Service\Writer\RecordWriterInterface;

/**
 * The test data of a chat run: its own root with pages, articles and elements, a news
 * archive, a calendar, an FAQ category, an image size, and three back-end users.
 *
 * Written through the core's RecordWriterInterface, the write path of the bundle's own
 * commands (versions, callbacks, cache tags) — not with raw SQL. Reads are plain SQL.
 * The ids of a build are kept in a state file, so a later run removes what an aborted
 * one left behind.
 */
final class ChatFixture
{
    public const OPERATOR = 'chat-runner';

    /** Containers whose children go with them on delete. */
    private const CONTAINERS = ['tl_image_size', 'tl_faq_category', 'tl_calendar', 'tl_news_archive', 'tl_page'];

    /** @var array<string, array{0: string, 1: int}> ref => [table, id] */
    private array $refs = [];

    /** @var array<string, int> highest id per container table when the build finished */
    private array $maxAfterBuild = [];

    private int $builtAt = 0;

    public function __construct(
        private readonly RecordWriterInterface $writer,
        private readonly Connection $db,
        private readonly ContaoFramework $framework,
        private readonly string $stateFile,
    ) {
    }

    public function id(string $ref): int
    {
        return $this->refs[$ref][1] ?? throw new \InvalidArgumentException("Unknown fixture record: {$ref}");
    }

    public function table(string $ref): string
    {
        return $this->refs[$ref][0] ?? throw new \InvalidArgumentException("Unknown fixture record: {$ref}");
    }

    public function has(string $ref): bool
    {
        return isset($this->refs[$ref]);
    }

    /**
     * @param array<string, mixed> $spec the `fixture` section of scenarios.yaml
     */
    public function rebuild(array $spec): void
    {
        $this->teardown();
        $this->build($spec);
    }

    /**
     * @param array<string, mixed> $spec
     */
    public function build(array $spec): void
    {
        $this->refs = [];
        $sorting = 128;
        $next = static function () use (&$sorting): int {
            return $sorting += 128;
        };

        $layout = (int) $this->db->fetchOne('SELECT id FROM tl_layout ORDER BY id LIMIT 1');
        $theme  = (int) $this->db->fetchOne('SELECT id FROM tl_theme ORDER BY id LIMIT 1');

        $pages = (array) ($spec['pages'] ?? []);
        // Roots first, then the pages below them, in the order written.
        foreach ([true, false] as $roots) {
            foreach ($pages as $ref => $page) {
                if ($roots !== !isset($page['parent'])) {
                    continue;
                }
                $fields = [
                    'pid'       => $roots ? 0 : $this->id((string) $page['parent']),
                    'sorting'   => $next(),
                    'type'      => (string) ($page['type'] ?? 'regular'),
                    'title'     => (string) $page['title'],
                    'alias'     => self::alias((string) $page['title'], (string) $ref),
                    'published' => self::flag($page['published'] ?? '1'),
                ];
                if ($roots) {
                    $fields += [
                        'language'      => (string) $page['language'],
                        'dns'           => (string) ($page['dns'] ?? ''),
                        'urlPrefix'     => (string) ($page['urlPrefix'] ?? ''),
                        'fallback'      => self::flag($page['fallback'] ?? ''),
                        'includeLayout' => $layout > 0 ? 1 : 0,
                        'layout'        => $layout,
                        // Page rights for everybody (`w`): without them Contao's default
                        // (owner and group 0) lets no editor touch an article here. What
                        // each test user may do is still decided by the modules, page
                        // mounts and field rights of their group.
                        'includeChmod'  => 1,
                        'cuser'         => 0,
                        'cgroup'        => 0,
                        'chmod'         => serialize(['u1', 'u2', 'u3', 'u4', 'u5', 'u6', 'w1', 'w2', 'w3', 'w4', 'w5', 'w6']),
                    ];
                }
                $this->create((string) $ref, 'tl_page', $fields);
            }
        }

        foreach ((array) ($spec['articles'] ?? []) as $ref => $article) {
            $page = $this->id((string) $article['page']);
            $fields = ['title' => (string) $article['title'], 'alias' => self::alias((string) $article['title'], (string) $ref), 'inColumn' => 'main', 'published' => 1];
            // Contao gives a new page its first article (content composition); a second
            // one would make every "the article on page X" ambiguous for the model.
            $existing = (int) $this->db->fetchOne('SELECT id FROM tl_article WHERE pid = ? ORDER BY sorting, id LIMIT 1', [$page]);
            if ($existing > 0) {
                $this->writer->update('tl_article', $existing, $fields, self::OPERATOR);
                $this->refs[(string) $ref] = ['tl_article', $existing];
            } else {
                $this->create((string) $ref, 'tl_article', ['pid' => $page, 'sorting' => $next()] + $fields);
            }
        }

        // Translation links (v0.21.0), through the writer like every other field. Only where
        // changelanguage is installed: without it there is no languageMain, and the
        // scenarios that need links are skipped by their `requires`.
        $columns = array_change_key_case($this->db->createSchemaManager()->listTableColumns('tl_page'));
        if (isset($columns['languagemain'])) {
            foreach (['pages' => 'tl_page', 'articles' => 'tl_article'] as $key => $table) {
                foreach ((array) ($spec[$key] ?? []) as $ref => $record) {
                    if (isset($record['languageMain'])) {
                        $this->writer->update($table, $this->id((string) $ref), ['languageMain' => $this->id((string) $record['languageMain'])], self::OPERATOR);
                    }
                }
            }
        }

        foreach ((array) ($spec['content'] ?? []) as $ref => $element) {
            $fields = ['pid' => $this->id((string) $element['article']), 'ptable' => 'tl_article', 'sorting' => $next(), 'type' => (string) $element['type']];
            foreach (['text', 'html'] as $field) {
                if (isset($element[$field])) {
                    $fields[$field] = (string) $element[$field];
                }
            }
            $fields['invisible'] = self::flag($element['invisible'] ?? '');
            if (isset($element['headline'])) {
                $fields['headline'] = serialize(['value' => (string) $element['headline']['value'], 'unit' => (string) $element['headline']['unit']]);
            }
            $this->create((string) $ref, 'tl_content', $fields);
        }

        foreach ((array) ($spec['news_archives'] ?? []) as $ref => $archive) {
            $this->create((string) $ref, 'tl_news_archive', ['title' => (string) $archive['title'], 'jumpTo' => $this->id((string) $archive['jumpTo'])]);
        }
        foreach ((array) ($spec['news'] ?? []) as $ref => $news) {
            $date = (int) strtotime((string) $news['date'] . ' 10:00');
            $this->create((string) $ref, 'tl_news', [
                'pid' => $this->id((string) $news['archive']), 'headline' => (string) $news['headline'],
                'alias' => self::alias((string) $news['headline'], (string) $ref), 'date' => $date, 'time' => $date,
                'published' => self::flag($news['published'] ?? ''), 'teaser' => (string) ($news['teaser'] ?? ''), 'author' => 0,
            ]);
        }

        foreach ((array) ($spec['calendars'] ?? []) as $ref => $calendar) {
            $this->create((string) $ref, 'tl_calendar', ['title' => (string) $calendar['title'], 'jumpTo' => $this->id((string) $calendar['jumpTo'])]);
        }
        foreach ((array) ($spec['events'] ?? []) as $ref => $event) {
            $start = (int) strtotime((string) $event['startDate']);
            $this->create((string) $ref, 'tl_calendar_events', [
                'pid' => $this->id((string) $event['calendar']), 'title' => (string) $event['title'],
                'alias' => self::alias((string) $event['title'], (string) $ref), 'startDate' => $start, 'startTime' => $start, 'endTime' => $start,
                'location' => (string) ($event['location'] ?? ''), 'published' => self::flag($event['published'] ?? ''), 'author' => 0,
            ]);
        }

        foreach ((array) ($spec['faq_categories'] ?? []) as $ref => $category) {
            $this->create((string) $ref, 'tl_faq_category', ['title' => (string) $category['title'], 'jumpTo' => $this->id((string) $category['jumpTo'])]);
        }
        foreach ((array) ($spec['faq'] ?? []) as $ref => $faq) {
            $this->create((string) $ref, 'tl_faq', [
                'pid' => $this->id((string) $faq['category']), 'sorting' => $next(), 'question' => (string) $faq['question'],
                'alias' => self::alias((string) $faq['question'], (string) $ref), 'answer' => (string) ($faq['answer'] ?? ''),
                'published' => self::flag($faq['published'] ?? ''), 'author' => 0,
            ]);
        }

        foreach ((array) ($spec['image_sizes'] ?? []) as $ref => $size) {
            $this->create((string) $ref, 'tl_image_size', [
                'pid' => $theme, 'name' => (string) $size['name'], 'width' => (string) ($size['width'] ?? ''),
                'height' => (string) ($size['height'] ?? ''), 'resizeMode' => (string) ($size['resizeMode'] ?? 'crop'),
            ]);
        }

        foreach (self::CONTAINERS as $table) {
            $this->maxAfterBuild[$table] = (int) $this->db->fetchOne("SELECT COALESCE(MAX(id), 0) FROM {$table}");
        }
        $this->builtAt = time();
        $this->saveState();
    }

    /**
     * The three users, with the rights of their role on this build's records. Created
     * once, updated on every build (the ids change). Returns role => username.
     *
     * @param array<string, array<string, mixed>> $users the `users` section
     *
     * @return array<string, string>
     */
    public function syncUsers(array $users): array
    {
        $names = [];
        $groups = [];
        foreach ($users as $role => $user) {
            $username = (string) $user['username'];
            $names[(string) $role] = $username;

            if (!empty($user['admin'])) {
                $fields = ['admin' => 1, 'inherit' => 'group', 'groups' => serialize([]), 'disable' => 0];
            } else {
                // Field rights (`alexf`) exist on groups only, as in the back end: a user
                // gets them through a group. One group per role, named after it.
                $group = 'Chat-Test ' . $role;
                $groupFields = $this->onlyColumns('tl_user_group', ['name' => $group, 'disable' => 0] + $this->rights($user));
                $groupId = (int) $this->db->fetchOne('SELECT id FROM tl_user_group WHERE name = ?', [$group]);
                if (0 === $groupId) {
                    $groupId = $this->writer->create('tl_user_group', $groupFields, self::OPERATOR);
                } else {
                    $this->writer->update('tl_user_group', $groupId, $groupFields, self::OPERATOR);
                }
                $groups[] = $group;
                $fields = ['admin' => 0, 'inherit' => 'group', 'groups' => serialize([(string) $groupId]), 'disable' => 0];
            }

            $id = (int) $this->db->fetchOne('SELECT id FROM tl_user WHERE username = ?', [$username]);
            if (0 === $id) {
                $fields += [
                    'username' => $username,
                    'name'     => 'Chat-Test ' . $role,
                    'email'    => $username . '@chat-test.local',
                    'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                    'language' => 'de',
                ];
                $this->writer->create('tl_user', $fields, self::OPERATOR);
            } else {
                $this->writer->update('tl_user', $id, $fields, self::OPERATOR);
            }
        }

        $state = $this->loadState();
        $state['users'] = array_values($names);
        $state['groups'] = $groups;
        $this->writeState($state);

        return $names;
    }

    /**
     * Only the columns this installation has: `news`, `calendars`, `faqs` come with
     * optional bundles.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function onlyColumns(string $table, array $fields): array
    {
        $columns = array_map('strtolower', array_keys($this->db->createSchemaManager()->listTableColumns($table)));

        return array_filter($fields, static fn (string $field): bool => \in_array(strtolower($field), $columns, true), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    private function rights(array $user): array
    {
        $ids = fn (array $refs): string => serialize(array_map(fn ($ref): string => (string) $this->id((string) $ref), $refs));

        $alexf = [];
        $rights = $user['alexf'] ?? [];
        if (\is_string($rights) && str_starts_with($rights, 'all fields of ')) {
            $this->framework->initialize();
            foreach (array_map('trim', explode(',', substr($rights, \strlen('all fields of ')))) as $table) {
                $this->framework->getAdapter(Controller::class)->loadDataContainer($table);
                foreach (array_keys($GLOBALS['TL_DCA'][$table]['fields'] ?? []) as $field) {
                    $alexf[] = $table . '::' . $field;
                }
            }
        } elseif (\is_array($rights)) {
            $alexf = array_values(array_map('strval', $rights));
        }

        $cud = [];
        if (\is_string($user['cud'] ?? null)) {
            // "create, update, delete on tl_news, tl_calendar_events; create on tl_news_archive"
            foreach (explode(';', $user['cud']) as $part) {
                if (!preg_match('/^\s*(.+?)\s+on\s+(.+)$/', $part, $m)) {
                    continue;
                }
                foreach (array_map('trim', explode(',', $m[2])) as $table) {
                    foreach (array_map('trim', explode(',', $m[1])) as $action) {
                        $cud[] = $table . '::' . $action;
                    }
                }
            }
        }

        return [
            'modules'    => serialize(array_values((array) ($user['modules'] ?? []))),
            'themes'     => serialize(array_values((array) ($user['themes'] ?? []))),
            'pagemounts' => $ids((array) ($user['pagemounts'] ?? [])),
            'news'       => $ids((array) ($user['news'] ?? [])),
            'calendars'  => $ids((array) ($user['calendars'] ?? [])),
            'faqs'       => $ids((array) ($user['faqs'] ?? [])),
            'alexf'      => serialize($alexf),
            'cud'        => serialize($cud),
            'elements'   => serialize(array_values((array) ($user['elements'] ?? []))),
            'alpty'      => serialize(array_values((array) ($user['alpty'] ?? []))),
            // the allow-list of image sizes, `userRoot` of tl_image_size
            'imageSizes' => $ids((array) ($user['imageSizes'] ?? [])),
        ];
    }

    /**
     * Removes this build and whatever a scenario added in its containers' tables: a new
     * record whose first version was written by a test user or the runner, or a root of
     * the fixture's domain. Anything else new is reported, not deleted: the test installation
     * may be shared. The test used to be the title — an archive "Aktuelles 2027" made by
     * someone else would have gone too.
     *
     * @return list<string> records found new but left alone
     */
    public function teardown(): array
    {
        $state = $this->loadState();
        $leftAlone = [];
        $authors = [self::OPERATOR, ...array_map('strval', (array) ($state['users'] ?? []))];

        // What scenarios created next to the fixture (a cloned archive, a new image size,
        // a root the voters should have refused).
        foreach ((array) ($state['maxAfterBuild'] ?? []) as $table => $max) {
            $label = 'tl_image_size' === $table ? 'name' : 'title';
            $rows = $this->db->fetchAllAssociative("SELECT * FROM {$table} WHERE id > ?", [(int) $max]);
            foreach ($rows as $row) {
                if ('tl_page' === $table && 'root' !== ($row['type'] ?? '')) {
                    continue; // pages below a fixture root go with it
                }
                if (str_contains((string) ($row['dns'] ?? ''), 'chat-test') || $this->writtenBy($table, (int) $row['id'], $authors)) {
                    $this->delete($table, (int) $row['id']);
                } else {
                    $leftAlone[] = "{$table}.{$row['id']} \"" . ($row[$label] ?? '') . '"';
                }
            }
        }

        foreach (array_reverse((array) ($state['records'] ?? []), true) as $entry) {
            [$table, $id] = $entry;
            if (\in_array($table, self::CONTAINERS, true)) {
                $this->delete((string) $table, (int) $id);
            }
        }

        // A root of the fixture's domain that no state file knows (state lost).
        foreach ($this->db->fetchFirstColumn("SELECT id FROM tl_page WHERE type = 'root' AND dns LIKE '%chat-test.local'") as $id) {
            $this->delete('tl_page', (int) $id);
        }

        $state['records'] = [];
        $state['maxAfterBuild'] = [];
        $this->writeState($state);
        $this->refs = [];

        return $leftAlone;
    }

    public function removeUsers(): void
    {
        $state = $this->loadState();
        foreach ((array) ($state['users'] ?? []) as $username) {
            $id = (int) $this->db->fetchOne('SELECT id FROM tl_user WHERE username = ?', [(string) $username]);
            if ($id > 0) {
                $this->writer->delete('tl_user', $id, self::OPERATOR, 0);
            }
        }
        foreach ((array) ($state['groups'] ?? []) as $group) {
            $id = (int) $this->db->fetchOne('SELECT id FROM tl_user_group WHERE name = ?', [(string) $group]);
            if ($id > 0) {
                $this->writer->delete('tl_user_group', $id, self::OPERATOR, 0);
            }
        }
        $state['users'] = [];
        $state['groups'] = [];
        $this->writeState($state);
    }

    public function builtAt(): int
    {
        return $this->builtAt;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function create(string $ref, string $table, array $fields): void
    {
        $this->refs[$ref] = [$table, $this->writer->create($table, $fields, self::OPERATOR)];
    }

    private function delete(string $table, int $id): void
    {
        if (false !== $this->db->fetchOne("SELECT id FROM {$table} WHERE id = ?", [$id])) {
            $this->writer->delete($table, $id, self::OPERATOR, 0);
        }
    }

    /**
     * A boolean column takes 0 or 1; the writer persists what it is handed, and strict
     * SQL refuses '' there.
     */
    private static function flag(mixed $value): int
    {
        return \in_array($value, [true, 1, '1'], true) ? 1 : 0;
    }

    /**
     * Whether the record's first version names one of these users — a write through the
     * bundle versions every new record under the acting user.
     *
     * @param list<string> $authors
     */
    private function writtenBy(string $table, int $id, array $authors): bool
    {
        $first = $this->db->fetchOne('SELECT username FROM tl_version WHERE fromTable = ? AND pid = ? ORDER BY version, id LIMIT 1', [$table, $id]);

        return \is_string($first) && \in_array($first, $authors, true);
    }

    private static function alias(string $title, string $ref): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtr($title, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue'])), '-'));

        return 'chat-test-' . str_replace('_', '-', $ref) . ('' !== $slug ? '-' . $slug : '');
    }

    private function saveState(): void
    {
        $state = $this->loadState();
        $state['records'] = array_values($this->refs);
        $state['refs'] = $this->refs;
        $state['maxAfterBuild'] = $this->maxAfterBuild;
        $this->writeState($state);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadState(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }
        $state = json_decode((string) file_get_contents($this->stateFile), true);

        return \is_array($state) ? $state : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeState(array $state): void
    {
        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
