<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * The files of a message must not outlive it (v1.0.0): ChatAttachments is a shared
 * service, and a worker serves the next user's request with the same instance.
 *
 * ChatAttachmentsTest checks that reset() empties the object; nothing there checks
 * that the controller calls it. The stream runs the agent synchronously inside one
 * try, so the guard is structural: set() inside that try, reset() in its finally.
 * Read from tokens, so braces in strings and comments do not count.
 */
class AttachmentsAreEmptiedTest extends TestCase
{
    private const SOURCE = __DIR__ . '/../../../src/Controller/AiStreamController.php';

    public function testTheFilesAreSetInsideTheTryWhoseFinallyEmptiesThem(): void
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize((string) file_get_contents(self::SOURCE)),
            static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
        $code = array_map(static fn (\PhpToken $t): string => $t->text, $tokens);

        $set = self::find($code, ['chatAttachments', '->', 'set', '(']);
        self::assertNotNull($set, 'chatAttachments->set( not found');

        // the innermost try that opens before set() and closes after it
        $enclosing = null;
        foreach ($tokens as $i => $token) {
            if ($i < $set && $token->is(T_TRY)) {
                $end = self::blockEnd($code, $i + 1);
                if ($end > $set) {
                    $enclosing = [$i, $end];
                }
            }
        }
        self::assertNotNull($enclosing, 'set() is not inside a try');

        // past the catch blocks to the finally
        $i = $enclosing[1] + 1;
        while ($tokens[$i]->is(T_CATCH)) {
            $i = self::blockEnd($code, array_search('{', \array_slice($code, $i, null, true), true)) + 1;
        }
        self::assertTrue($tokens[$i]->is(T_FINALLY), 'the try around set() has no finally');

        $finallyEnd = self::blockEnd($code, $i + 1);
        $reset = self::find(\array_slice($code, $i, $finallyEnd - $i), ['chatAttachments', '->', 'reset', '(']);
        self::assertNotNull($reset, 'the finally around set() does not call chatAttachments->reset()');
    }

    /**
     * @param list<string> $code
     * @param list<string> $needle
     */
    private static function find(array $code, array $needle): ?int
    {
        $n = \count($needle);
        foreach (array_keys($code) as $i) {
            if (\array_slice($code, $i, $n) === $needle) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Index of the `}` that closes the block opening at the first `{` from $from.
     *
     * @param list<string> $code
     */
    private static function blockEnd(array $code, int|string|false $from): int
    {
        $depth = 0;
        for ($i = (int) $from, $n = \count($code); $i < $n; ++$i) {
            if (\in_array($code[$i], ['{', '${'], true)) { // "{$x}" in a string opens with '{' too
                ++$depth;
            } elseif ('}' === $code[$i]) {
                if (0 === --$depth) {
                    return $i;
                }
            }
        }

        self::fail('unbalanced braces');
    }
}
