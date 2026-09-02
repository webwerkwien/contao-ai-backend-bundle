<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use PHPUnit\Framework\TestCase;

/**
 * A record that does not exist is not a server error.
 *
 * 🔴 Found on 2026-09-02 while live-testing the bridge with a wrong id:
 *
 *     bridge clone --table tl_faq_category --source-id 1
 *     -> HTTP 500: Tool "record_clone" fehlgeschlagen: FAQ-Kategorie 1 nicht gefunden.
 *
 * Every failure of an underlying console command became `ToolExecutionException`
 * and therefore HTTP 500, so a typo in an id was indistinguishable from a crash.
 *
 * 🎯 It only became urgent because something started *reading* that code.
 * contao-ai-cli v0.15.0 stopped treating a 500 from `bridge configure --test` as
 * "auth OK" and began reporting `auth_ok_server_error` — *"your token works, the
 * bridge is broken"*. A mistyped id would then have accused a healthy bridge.
 * One status number carrying two meanings is harmless until it is used.
 *
 * ⚠️ **The trap in this fix was the second catcher.** `ToolExecutionException`
 * is caught in two places: the bridge controller *and* `AiStreamController` for
 * the backend chat. Splitting refusals off for the bridge alone would have made
 * them fall through to `\Throwable` in the chat and render as `agent_failed` —
 * a missing record looking like a crashed agent, which is worse than the bug
 * being fixed. Changing an exception type is only half the change; finding
 * everyone who catches it is the other half.
 */
class RefusalIsNotAServerErrorTest extends TestCase
{
    private function sourceOf(string $relative): string
    {
        $path = __DIR__ . '/../../../src/' . $relative;
        $source = (string) file_get_contents($path);

        self::assertGreaterThan(200, \strlen($source), "$relative read as almost nothing");

        return $source;
    }

    public function testTheToolThrowsARefusalWhenTheCommandAnsweredWithAnError(): void
    {
        // The branch reached when the console command produced a well-formed
        // `{"status":"error","message":…}` — it ran, understood the request and
        // declined. That is a statement to the caller, not a defect.
        $source = $this->sourceOf('Tool/AbstractCoreCommandTool.php');

        self::assertStringContainsString(
            'throw new ToolRefusedException(',
            $source,
            'a structured error answer is a refusal, not an execution failure',
        );
    }

    public function testGenuineFailuresStayExecutionErrors(): void
    {
        // No JSON, an unusable shape, an unserialisable answer: the command could
        // not answer at all, and that is ours to own. These must NOT become 422.
        $source = $this->sourceOf('Tool/AbstractCoreCommandTool.php');

        foreach ([
            'lieferte kein gültiges JSON',
            'lieferte kein JSON-Objekt zurück',
            'Ausgabe konnte nicht serialisiert werden',
        ] as $marker) {
            $offset = strpos($source, $marker);
            self::assertNotFalse($offset, "the branch for '$marker' disappeared");

            $before = substr($source, max(0, $offset - 300), 300);
            self::assertStringContainsString(
                'ToolExecutionException',
                $before,
                "'$marker' must stay a server error, not become a refusal",
            );
        }
    }

    public function testTheBridgeAnswers422ForARefusal(): void
    {
        $source = $this->sourceOf('Controller/CliBridgeController.php');

        self::assertMatchesRegularExpression(
            '/catch \(ToolRefusedException \$e\) \{.*?return \$this->error\(422,/s',
            $source,
            'a refusal must not be a 500 — contao-ai-cli reads that as a broken bridge',
        );
    }

    /**
     * 🎯 The assertion this whole test exists for.
     *
     * Two controllers catch `ToolExecutionException`. Handling only the bridge
     * would have moved chat refusals from `tool_failed` to `agent_failed`.
     */
    public function testTheChatControllerHandlesRefusalsToo(): void
    {
        $source = $this->sourceOf('Controller/AiStreamController.php');

        self::assertStringContainsString(
            'catch (ToolRefusedException $e)',
            $source,
            'chat refusals would fall through to \Throwable and read as agent_failed',
        );
        self::assertStringContainsString(
            "'kind' => 'tool_refused'",
            $source,
            'a refusal needs its own kind, or the reader cannot tell it from a crash',
        );
    }

    public function testEveryCatcherOfExecutionErrorsAlsoCatchesRefusals(): void
    {
        // Derived rather than listed: a third catcher added later is exactly the
        // shape that caused this, and a fixed list of two would not notice it.
        // ⚠️ Recursive on purpose. The first version used
        // `glob('src/**/*.php')`, which sees 32 of the 50 files — both catchers
        // happened to sit in one of the visible directories, so it passed while
        // being blind to 18 files. A test whose coverage depends on where
        // someone puts the next file is the same defect it is meant to catch.
        $catchers = [];
        $missing  = [];
        $scanned  = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../../../src')
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            ++$scanned;
            $source = (string) file_get_contents($file->getPathname());

            if (!str_contains($source, 'catch (ToolExecutionException')) {
                continue;
            }

            $catchers[] = $file->getFilename();

            if (!str_contains($source, 'catch (ToolRefusedException')) {
                $missing[] = $file->getFilename();
            }
        }

        self::assertGreaterThan(40, $scanned, "the scan only saw $scanned files of src/");
        self::assertGreaterThanOrEqual(2, \count($catchers), 'the scan found fewer catchers than exist');
        self::assertSame([], $missing, 'catches execution errors but not refusals: ' . implode(', ', $missing));
    }
}
