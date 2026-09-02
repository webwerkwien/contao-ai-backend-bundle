<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use PHPUnit\Framework\TestCase;

/**
 * Every argument a tool sends must exist on the command it calls.
 *
 * On 2026-09-02 the chat produced:
 *
 *     [agent_failed] Tool "page_publish" konnte nicht ausgeführt werden:
 *     The "--published" option does not exist.
 *
 * `contao:page:publish` takes two positional arguments — `id` and
 * `publish`/`unpublish` — and never had a `--published` option. `PageTool`
 * had been sending one since the tool was written, so **page_publish failed in
 * both directions**, and nobody noticed: no test covered it, and the first
 * person to reach it was the first person to try unpublishing from the chat.
 *
 * 🎯 `runCommand()` already asks `hasOption('operator')` before adding the
 * operator — the very check that would have caught this — and applies it to
 * exactly that one key. Everything the caller passes goes through unchecked.
 *
 * This test reads both sides from source rather than running anything: the
 * literal argument arrays in the tools, and the `addArgument`/`addOption` names
 * in the core bundle's command classes. A mismatch fails here instead of in
 * somebody's chat window.
 */
class ToolArgumentsMatchCommandTest extends TestCase
{
    private static function toolDir(): string
    {
        return \dirname(__DIR__, 3).'/src/Tool';
    }

    private static function commandDir(): string
    {
        return \dirname(__DIR__, 3).'/vendor/webwerkwien/contao-ai-core-bundle/src/Command';
    }

    /**
     * Argument and option names a command declares in configure().
     *
     * @return array{args: list<string>, opts: list<string>}|null
     */
    private static function definitionOf(string $commandClass): ?array
    {
        $path = self::commandDir().'/'.$commandClass.'.php';

        if (!is_file($path)) {
            return null;
        }

        $source = file_get_contents($path);

        preg_match_all("/addArgument\\(\\s*'([^']+)'/", $source, $a);
        preg_match_all("/addOption\\(\\s*'([^']+)'/", $source, $o);

        // Follow `extends`: a concrete command like PageUpdateCommand declares
        // nothing of its own — `id` and `--set` live in AbstractModelUpdateCommand.
        // Stopping at the class reported twelve mismatches that were only this
        // parser's short sight, which is its own small lesson: a checker that
        // reads one level deep answers confidently about two.
        if (preg_match('/class\\s+\\w+\\s+extends\\s+(\\w+)/', $source, $m)) {
            $parent = self::definitionOf($m[1]);

            if (null !== $parent) {
                return [
                    'args' => array_merge($a[1], $parent['args']),
                    'opts' => array_merge($o[1], $parent['opts']),
                ];
            }
        }

        return ['args' => $a[1], 'opts' => $o[1]];
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function toolCalls(): iterable
    {
        foreach (glob(self::toolDir().'/*.php') as $path) {
            $source = file_get_contents($path);
            $file   = basename($path);

            // runCommand($this->xyzCommand, [ 'key' => …, '--opt' => … ], 'tool')
            preg_match_all(
                "/runCommand\\(\\s*\\\$this->(\\w+Command)\\s*,\\s*\\[(.*?)\\]\\s*,\\s*'([^']+)'/s",
                $source,
                $calls,
                PREG_SET_ORDER,
            );

            foreach ($calls as [, $property, $arrayBody, $tool]) {
                preg_match_all("/'((?:--)?[a-zA-Z][\\w-]*)'\\s*=>/", $arrayBody, $keys);

                if ([] === $keys[1]) {
                    continue;
                }

                yield "$file: $tool" => [$file, $property, $keys[1]];
            }
        }
    }

    /**
     * @dataProvider toolCalls
     *
     * @param list<string> $keys
     */
    public function testEveryKeyExistsOnTheCommand(string $file, string $property, array $keys): void
    {
        // The property name does not carry the entity — `$this->publishCommand`
        // lives on PageTool and means PagePublishCommand. The constructor's type
        // hint is the only place the mapping exists, so read it there.
        $class      = self::commandClassFor($file, $property);
        $definition = null === $class ? null : self::definitionOf($class);

        self::assertNotNull($class, "no constructor type hint for \$$property in $file");
        self::assertNotNull($definition, "$class not found in the vendored core bundle");

        $unknown = [];

        foreach ($keys as $key) {
            if (str_starts_with($key, '--')) {
                if (!\in_array(substr($key, 2), $definition['opts'], true)) {
                    $unknown[] = $key;
                }
                continue;
            }

            if (!\in_array($key, $definition['args'], true)) {
                $unknown[] = $key;
            }
        }

        self::assertSame(
            [],
            $unknown,
            \sprintf(
                '%s sends %s to %s, which declares arguments [%s] and options [%s].',
                $file,
                implode(', ', $unknown),
                $class,
                implode(', ', $definition['args']),
                implode(', ', $definition['opts']),
            ),
        );
    }

    /**
     * property name -> command class, read from the constructor signature.
     */
    private static function commandClassFor(string $file, string $property): ?string
    {
        $source = @file_get_contents(self::toolDir().'/'.$file);

        if (false === $source) {
            return null;
        }

        $pattern = '/(?:private|protected|public)\s+readonly\s+(\w+)\s+\$'.preg_quote($property, '/').'\b/';

        return preg_match($pattern, $source, $m) ? $m[1] : null;
    }

    public function testTheScanFindsSomethingAtAll(): void
    {
        // A scan that silently matches nothing passes as quietly as one that
        // matches everything — the lesson this project keeps re-learning.
        self::assertGreaterThan(8, iterator_count(self::toolCalls()));
    }
}
