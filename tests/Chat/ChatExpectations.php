<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Chat;

use Doctrine\DBAL\Connection;

/**
 * Compares what a scenario did with its `expect` block: the outcome of the last turn,
 * the tools called, and the records in the database. Reads only.
 */
final class ChatExpectations
{
    /** A tool name that writes when it succeeds. */
    private const WRITING = '/(_create|_update|_delete|_publish|_link|^record_clone|^record_rewrite|^ext_run)$/';

    public function __construct(private readonly Connection $db, private readonly ChatFixture $fixture)
    {
    }

    /**
     * What has to be known before the turns run: version counts and the highest ids.
     *
     * @param array<string, mixed> $expect
     *
     * @return array<string, mixed>
     */
    public function snapshot(array $expect): array
    {
        $snapshot = ['versions' => [], 'maxId' => []];
        foreach ((array) ($expect['records'] ?? []) as $ref => $check) {
            $snapshot['versions'][$ref] = $this->versionCount($this->fixture->table((string) $ref), $this->fixture->id((string) $ref));
        }
        if (isset($expect['created']['table'])) {
            $table = (string) $expect['created']['table'];
            $snapshot['maxId'][$table] = (int) $this->db->fetchOne("SELECT COALESCE(MAX(id), 0) FROM {$table}");
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed>       $expect
     * @param array<string, mixed>       $snapshot
     * @param list<array<string, mixed>> $turns    what the runner recorded per turn
     *
     * @return list<string> what does not match; empty = passed
     */
    public function check(array $expect, array $snapshot, array $turns): array
    {
        $failures = [];
        $last = end($turns) ?: ['calls' => [], 'error' => null, 'text' => ''];

        $called = [];
        $writes = 0;
        foreach ($turns as $turn) {
            foreach ($turn['calls'] as $call) {
                $called[] = $call['name'];
            }
        }
        $staged = false;
        foreach ($last['calls'] as $call) {
            $result = (string) ($call['result'] ?? '');
            if (str_contains($result, 'pending_confirmation') || str_contains($result, 'awaiting_user')) {
                $staged = true;
            } elseif ($call['ok'] && preg_match(self::WRITING, (string) $call['name']) && !str_contains($result, '"status":"error"')) {
                ++$writes;
            }
        }
        $error = $last['error']['kind'] ?? null;

        $outcome = (string) ($expect['outcome'] ?? 'done');
        $asked = $staged || (0 === $writes && str_contains((string) $last['text'], '?'));
        $ok = match ($outcome) {
            'done'    => null === $error && !$staged,
            'asks'    => null === $error && $asked && 0 === $writes,
            'refused' => \in_array($error, ['access_denied', 'tool_refused'], true) || (null === $error && 0 === $writes && !$staged),
            default   => false,
        };
        if (!$ok) {
            $failures[] = \sprintf('outcome: expected %s, got %s', $outcome, $error ? "error {$error}" : ($staged ? 'a confirmation' : ($writes > 0 ? "{$writes} write(s)" : 'an answer without writes')));
        }

        foreach ((array) ($expect['calls'] ?? []) as $tool) {
            if (!\in_array($tool, $called, true)) {
                $failures[] = "not called: {$tool}";
            }
        }
        // Feeling the way through ten lookups is a failure too (showcase, 2026-10-19: 9–10
        // calls for one slider headline) — counted over all turns, failed calls excluded.
        if (isset($expect['max_calls'])) {
            $total = array_sum(array_map(static fn (array $turn): int => (int) ($turn['tool_calls'] ?? \count($turn['calls'])), $turns));
            if ($total > (int) $expect['max_calls']) {
                $failures[] = \sprintf('%d tool calls, at most %d expected', $total, (int) $expect['max_calls']);
            }
        }
        if ([] !== ($any = (array) ($expect['calls_any'] ?? [])) && [] === array_intersect($any, $called)) {
            $failures[] = 'none of these called: ' . implode(', ', $any);
        }
        foreach ((array) ($expect['not_calls'] ?? []) as $tool) {
            if (\in_array($tool, $called, true)) {
                $failures[] = "called although it must not be: {$tool}";
            }
        }
        // What the last answer has to say, or must not (v0.21.0): on showcase a chat told
        // the user a page existed in one language only — the database was right, the
        // sentence was wrong, and only the sentence was false.
        foreach (['says' => 1, 'says_not' => 0] as $key => $wanted) {
            foreach ((array) ($expect[$key] ?? []) as $pattern) {
                $found = @preg_match('/' . str_replace('/', '\/', (string) $pattern) . '/iu', (string) $last['text']);
                // A broken pattern must not pass a says_not silently (review L4).
                if (false === $found) {
                    $failures[] = "{$key}: invalid pattern /{$pattern}/";
                } elseif ($found !== $wanted) {
                    $failures[] = $wanted ? "answer does not say /{$pattern}/" : "answer says /{$pattern}/ although it must not";
                }
            }
        }

        foreach ((array) ($expect['records'] ?? []) as $ref => $check) {
            $table = $this->fixture->table((string) $ref);
            $id = $this->fixture->id((string) $ref);
            $row = $this->db->fetchAssociative("SELECT * FROM {$table} WHERE id = ?", [$id]) ?: null;

            if (\array_key_exists('gone', $check)) {
                if ((bool) $check['gone'] !== (null === $row)) {
                    $failures[] = "{$ref}: expected " . ($check['gone'] ? 'deleted' : 'still there');
                }
            }
            if (null === $row) {
                if (!empty($check['fields']) || isset($check['versions'])) {
                    $failures[] = "{$ref}: record is gone";
                }
                continue;
            }
            foreach ((array) ($check['fields'] ?? []) as $path => $expected) {
                $actual = self::value($row, (string) $path);
                if (!$this->matches($actual, $expected)) {
                    $failures[] = \sprintf('%s.%s: expected %s, got %s', $ref, $path, json_encode($expected, JSON_UNESCAPED_UNICODE), json_encode($actual, JSON_UNESCAPED_UNICODE));
                }
            }
            if (isset($check['versions'])) {
                $new = $this->versionCount($table, $id) - (int) ($snapshot['versions'][$ref] ?? 0);
                $wanted = (int) $check['versions'];
                if (0 === $wanted ? 0 !== $new : $new < $wanted) {
                    $failures[] = "{$ref}: expected " . (0 === $wanted ? 'no new version' : "{$wanted} new version(s)") . ", got {$new}";
                }
            }
        }

        if (isset($expect['created'])) {
            $failures = [...$failures, ...$this->checkCreated((array) $expect['created'], $snapshot)];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $created
     * @param array<string, mixed> $snapshot
     *
     * @return list<string>
     */
    private function checkCreated(array $created, array $snapshot): array
    {
        $table = (string) $created['table'];
        $params = [(int) ($snapshot['maxId'][$table] ?? 0)];
        $sql = "SELECT * FROM {$table} WHERE id > ?";
        if (isset($created['parent'])) {
            $sql .= ' AND pid = ?';
            $params[] = $this->fixture->id((string) $created['parent']);
        }
        $rows = $this->db->fetchAllAssociative($sql, $params);
        if ([] === $rows) {
            return ["created: no new record in {$table}" . (isset($created['parent']) ? " below {$created['parent']}" : '')];
        }
        foreach ($rows as $row) {
            $all = true;
            foreach ((array) ($created['fields'] ?? []) as $path => $expected) {
                if (!$this->matches(self::value($row, (string) $path), $expected)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return [];
            }
        }

        return ['created: ' . \count($rows) . " new record(s) in {$table}, none with " . json_encode($created['fields'] ?? [], JSON_UNESCAPED_UNICODE)
            . ' — first: ' . json_encode(array_intersect_key($rows[0], array_flip(array_map(static fn ($p) => explode('.', (string) $p)[0], array_keys((array) ($created['fields'] ?? []))))), JSON_UNESCAPED_UNICODE)];
    }

    /**
     * `headline.unit`: the part after the dot is read from a serialized or JSON value.
     *
     * @param array<string, mixed> $row
     */
    private static function value(array $row, string $path): mixed
    {
        $parts = explode('.', $path, 2);
        $value = $row[$parts[0]] ?? null;
        if (!isset($parts[1])) {
            return $value;
        }
        $decoded = \is_string($value) ? @unserialize($value, ['allowed_classes' => false]) : null;
        if (!\is_array($decoded) && \is_string($value)) {
            $decoded = json_decode($value, true);
        }

        return \is_array($decoded) ? ($decoded[$parts[1]] ?? null) : null;
    }

    private function matches(mixed $actual, mixed $expected): bool
    {
        if (\is_bool($expected)) {
            return $expected === (bool) (int) $actual;
        }
        if (\is_int($expected) || \is_float($expected)) {
            return is_numeric($actual) && (float) $actual === (float) $expected;
        }
        if (\is_array($expected)) {
            $text = html_entity_decode((string) $actual, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (isset($expected['contains'])) {
                return str_contains($text, (string) $expected['contains']);
            }
            if (isset($expected['date'])) {
                return is_numeric($actual) && date('Y-m-d', (int) $actual) === (string) $expected['date'];
            }
            if (isset($expected['ref'])) {
                return (int) $actual === $this->fixture->id((string) $expected['ref']);
            }

            return false;
        }

        return html_entity_decode((string) $actual, ENT_QUOTES | ENT_HTML5, 'UTF-8') === (string) $expected;
    }

    private function versionCount(string $table, int $id): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_version WHERE fromTable = ? AND pid = ?', [$table, $id]);
    }
}
