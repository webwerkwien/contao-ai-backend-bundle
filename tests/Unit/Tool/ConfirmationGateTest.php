<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use PHPUnit\Framework\TestCase;

/**
 * Every tool that takes content away asks first.
 *
 * The gate is four hand-written calls to `requireConfirmation()`, one per
 * delete method. That is the "everyone must remember" shape, and on 2026-09-02
 * it had already failed: `page_publish` also unpublishes — taking a live page
 * offline — and calls nothing, while `AbstractCoreCommandTool` states in its
 * own docblock that the gate covers *"delete, unpublish"*.
 *
 * 🎯 **The documentation promised a protection that did not exist**, and
 * nothing failed, because forty tests covered everything except this.
 *
 * ## Why this is a list and not derived from the contract
 *
 * Tempting, and wrong. `#[AiContract]` says `irreversible` means *an effect
 * outside the database that cannot be taken back*. A `page_delete` does not
 * qualify — it cascades into one `tl_undo` entry and the back end restores it.
 * Neither does unpublishing.
 *
 * So the gate is not a statement about the operation; it is this bundle's UX
 * policy about what a chat agent should not do without asking. Inventing a
 * contract field to carry it would tailor the contract to one consumer, which
 * its own documentation warns against.
 *
 * The list below is therefore maintained on purpose — but a *new* tool cannot
 * quietly join it, because anything named like a removal has to be either
 * gated or explicitly excused here.
 */
class ConfirmationGateTest extends TestCase
{
    /** Tool methods that must not run without a staged confirmation. */
    private const MUST_ASK = [
        'ArticleTool.php'  => ['delete'],
        'ContentTool.php'  => ['delete'],
        'NewsTool.php'     => ['delete'],
        'PageTool.php'     => ['delete', 'publish'],
    ];

    /**
     * Named exceptions, each with the reason it is one.
     *
     * `record_clone` creates rather than removes. It is not harmless — v0.2.15
     * of the core bundle exists because a clone put two pages live that were
     * meant to stay unpublished — but the answer there was to report ignored
     * modifications, not to ask first.
     */
    private const EXCUSED = ['RecordCloneTool.php' => 'creates, does not remove'];

    private static function toolDir(): string
    {
        return \dirname(__DIR__, 3).'/src/Tool';
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function gatedMethods(): iterable
    {
        foreach (self::MUST_ASK as $file => $methods) {
            foreach ($methods as $method) {
                yield "$file::$method" => [$file, $method];
            }
        }
    }

    /**
     * @dataProvider gatedMethods
     */
    public function testItAsksBeforeTakingSomethingAway(string $file, string $method): void
    {
        $body = self::methodBody($file, $method);

        self::assertNotSame('', $body, "$file::$method() not found");
        self::assertStringContainsString(
            'requireConfirmation',
            $body,
            "$file::$method() runs without asking. Either gate it, or move it to EXCUSED with a reason.",
        );
    }

    /**
     * Nothing new slips in ungated.
     *
     * Scans every tool file for methods whose name reads like a removal and
     * checks they are accounted for — either in MUST_ASK or in EXCUSED. A
     * name-based scan is a poor judge of what is destructive, which is exactly
     * why it only ever *raises the question* here rather than deciding.
     */
    public function testNoRemovalMethodIsUnaccountedFor(): void
    {
        $unaccounted = [];

        foreach (glob(self::toolDir().'/*.php') as $path) {
            $file    = basename($path);
            $source  = file_get_contents($path);
            $known   = self::MUST_ASK[$file] ?? [];

            if (isset(self::EXCUSED[$file])) {
                continue;
            }

            preg_match_all(
                "/#\\[AsTool\\(\\s*'([^']+)'[^\\]]*method:\\s*'(\\w+)'/s",
                $source,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as [, $tool, $method]) {
                $looksLikeRemoval = (bool) preg_match('/delete|remove|unpublish|purge|reset/i', $tool);

                if ($looksLikeRemoval && !\in_array($method, $known, true)) {
                    $unaccounted[] = "$file::$method ($tool)";
                }
            }
        }

        self::assertSame(
            [],
            $unaccounted,
            'Tools that read like a removal and are neither gated nor excused: '.implode(', ', $unaccounted),
        );
    }

    private static function methodBody(string $file, string $method): string
    {
        $source = @file_get_contents(self::toolDir().'/'.$file);

        if (false === $source) {
            return '';
        }

        $pattern = '/function\s+'.preg_quote($method, '/')
            .'\s*\(.*?(?=\n    (?:public|protected|private)\s|\n\})/s';

        return preg_match($pattern, $source, $m) ? $m[0] : '';
    }
}
