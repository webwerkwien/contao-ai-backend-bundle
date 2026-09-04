<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * A report is offered for defects, and for nothing else.
 *
 * Three of the four error branches are *answers*: a permission that worked
 * (`access_denied`), a command that looked and declined (`tool_refused`), a
 * setup that is incomplete (the 412 above the try). Attaching "report this to
 * the maintainer" to those would train users to send noise, and noise is how the
 * two real cases get buried.
 *
 * The distinction is not new — it is the 422/500 split drawn on 2026-09-02 for
 * the bridge's status codes, which turns out to be exactly the line between bug
 * and not-a-bug. This test is what keeps the two from drifting apart, because
 * nothing else would notice: adding `'report'` to the refusal branch breaks
 * nothing, it just quietly makes every mistyped id look like a defect.
 *
 * ## Why this reads source instead of calling the controller
 *
 * `AiStreamController::__invoke()` needs a Contao framework, a backend session,
 * a CSRF token and a live agent before it reaches a catch block. The assertion
 * here is about which branch carries which key — a structural property, and
 * structure is what source text answers honestly. The counters below are the
 * guard against the failure mode of every scanning test: matching nothing and
 * reporting success.
 */
class ErrorReportOnlyForRealFailuresTest extends TestCase
{
    /** @var array<string, bool> kind => must carry a report */
    private const BRANCHES = [
        'access_denied' => false,
        'tool_refused'  => false,
        'tool_failed'   => true,
        'agent_failed'  => true,
    ];

    private function controllerSource(): string
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/Controller/AiStreamController.php');

        self::assertGreaterThan(1000, \strlen($source), 'AiStreamController read as almost nothing');

        return $source;
    }

    /**
     * @return array<string, string> kind => the emit call that produced it
     */
    private function errorEmits(): array
    {
        preg_match_all("/\\\$emit\('error', \[(.*?)\]\);/s", $this->controllerSource(), $matches);

        $emits = [];

        foreach ($matches[1] as $body) {
            self::assertSame(
                1,
                preg_match("/'kind'\s*=>\s*'([a-z_]+)'/", $body, $kind),
                "an error emit without a literal 'kind' — this test cannot classify it:\n" . $body,
            );

            $emits[$kind[1]] = $body;
        }

        return $emits;
    }

    /**
     * The counter. A branch that is added, renamed or removed lands here first.
     */
    public function testEveryErrorBranchIsAccountedFor(): void
    {
        self::assertSame(
            array_keys(self::BRANCHES),
            array_keys($this->errorEmits()),
            'the error branches changed — decide for the new one whether a defect report belongs to it, then update BRANCHES',
        );
    }

    public function testOnlyGenuineFailuresCarryAReport(): void
    {
        foreach ($this->errorEmits() as $kind => $body) {
            $carries = str_contains($body, "'report'");

            self::assertSame(
                self::BRANCHES[$kind],
                $carries,
                self::BRANCHES[$kind]
                    ? "'$kind' is a defect and must offer a report"
                    : "'$kind' is an answer, not a defect — offering a report there teaches users to send noise",
            );
        }
    }

    /**
     * The audience decision has to happen server-side.
     *
     * An earlier draft had the browser fetch the full report on demand, which
     * needs the failure to outlive the request — a store, which is what the
     * design set out not to build. Deciding at emit time is both cheaper and
     * stronger: an editor never receives the message rather than being trusted
     * not to ask for it.
     */
    public function testTheFullReportIsReservedForAdmins(): void
    {
        $source = $this->controllerSource();

        self::assertMatchesRegularExpression(
            '/isAdmin\s*\r?\n?\s*\?\s*\$report->toMarkdown\(true\)/',
            $source,
            'the full report (with the exception message) must be gated on isAdmin',
        );

        self::assertStringContainsString(
            'withoutMessage()',
            $source,
            'a non-admin must receive the summary with the message stripped, not merely a shorter rendering',
        );
    }

    /**
     * H-6 — but only where it applies, which is not everywhere.
     *
     * 🔴 The first version of this test asserted that no branch emits
     * `$e->getMessage()` and went red immediately, on code that is correct:
     * `access_denied` and `tool_refused` pass the message through **on purpose**.
     * Those strings are written by us ("FAQ-Kategorie 42 nicht gefunden") and are
     * addressed to the reader; sanitising them would replace an answer with
     * "Interner Fehler — siehe Logfile" and help nobody.
     *
     * H-6 exists because a *defect* message can carry PDO output, vendor paths,
     * an API key from a header dump, a DB DSN — text from code we do not
     * control. So the rule tracks the same line as the report itself: our own
     * answers pass through, foreign failures do not.
     *
     * That the two lines coincide is not a coincidence. Both ask the same
     * question — did we author this text, or did something else?
     */
    public function testForeignFailureMessagesAreNeverEmittedRaw(): void
    {
        $checked = 0;

        foreach ($this->errorEmits() as $kind => $body) {
            if (!self::BRANCHES[$kind]) {
                // Our own text, deliberately user-facing.
                continue;
            }

            ++$checked;

            self::assertStringNotContainsString(
                '$e->getMessage()',
                $body,
                "'$kind' carries text from code we do not control — it goes through safeMessage(), never raw",
            );
            self::assertStringContainsString(
                'safeMessage(',
                $body,
                "'$kind' must sanitise its message",
            );
        }

        self::assertSame(2, $checked, 'expected exactly the two defect branches to be checked here');
    }
}
