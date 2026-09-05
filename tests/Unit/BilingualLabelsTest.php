<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Everything a user sees in the back end exists in German and English.
 *
 * 🔴 2026-09-05. The language files were exemplary — `de/` and `en/` matched key
 * for key, including notes on why a key was dropped. And right next to them the
 * chat interface was hardcoded German: input field, submit button, tool count,
 * the copy button, and four controller messages. An English installation showed
 * German in the one place an editor looks most.
 *
 * 🎯 **The discipline held exactly where it was visible.** Nobody forgets to
 * translate a `$GLOBALS['TL_LANG']` entry — the file is called `languages/de`
 * and its counterpart sits next to it. A string in a Twig attribute or a
 * `textContent =` assignment carries no such reminder, so it never entered the
 * translation workflow at all. The gap is not where the work is; it is where
 * the work does not look like translation.
 *
 * Two of the offending strings were added the day before by the same session
 * that later fixed them — the report block from the error-reporting feature.
 * Writing the rule down did not prevent it; this test is the attempt that can.
 *
 * ## What this deliberately does NOT cover
 *
 * Console output of the core bundle and the CLI stay English. They address
 * agents and developers, not editors, and `TL_LANG` is not even loaded there.
 * The line runs by audience, not by file type: back-end interface bilingual,
 * tool output English (decision Michael, 2026-09-05).
 */
class BilingualLabelsTest extends TestCase
{
    private const LANG_DIR = __DIR__ . '/../../contao/languages';

    private const TEMPLATE = __DIR__ . '/../../src/Resources/views/Backend/chat.html.twig';

    /**
     * Loads one language directory into a fresh key list.
     *
     * The files write into `$GLOBALS['TL_LANG']`, so the global is cleared
     * around each load — otherwise German keys would still be present while
     * English is measured, and a missing English key would go unnoticed. That
     * is the failure this test exists to catch, so it must not be able to
     * happen inside the test itself.
     *
     * @return list<string> flattened keys, e.g. "ai_chat.send"
     */
    private function keysOf(string $language): array
    {
        $previous            = $GLOBALS['TL_LANG'] ?? null;
        $GLOBALS['TL_LANG']  = [];

        foreach (glob(self::LANG_DIR . '/' . $language . '/*.php') ?: [] as $file) {
            require $file;
        }

        $keys = $this->flatten($GLOBALS['TL_LANG']);
        sort($keys);

        $GLOBALS['TL_LANG'] = $previous;

        return $keys;
    }

    /**
     * @param  array<mixed> $node
     * @return list<string>
     */
    private function flatten(array $node, string $prefix = ''): array
    {
        $flat = [];

        foreach ($node as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

            if (\is_array($value)) {
                $flat = array_merge($flat, $this->flatten($value, $path));
                continue;
            }

            $flat[] = $path;
        }

        return $flat;
    }

    /** The counter: a comparison of two empty lists passes very happily. */
    public function testBothLanguagesActuallyContainSomething(): void
    {
        self::assertGreaterThanOrEqual(20, \count($this->keysOf('de')), 'German language files read as almost empty');
        self::assertGreaterThanOrEqual(20, \count($this->keysOf('en')), 'English language files read as almost empty');
    }

    public function testEveryGermanFileHasAnEnglishCounterpart(): void
    {
        $de = array_map('basename', glob(self::LANG_DIR . '/de/*.php') ?: []);
        $en = array_map('basename', glob(self::LANG_DIR . '/en/*.php') ?: []);

        sort($de);
        sort($en);

        self::assertSame($de, $en, 'the two language directories hold different files');
    }

    public function testTheKeysMatchInBothLanguages(): void
    {
        $de = $this->keysOf('de');
        $en = $this->keysOf('en');

        self::assertSame(
            [],
            array_values(array_diff($de, $en)),
            'these keys exist in German but not in English',
        );
        self::assertSame(
            [],
            array_values(array_diff($en, $de)),
            'these keys exist in English but not in German',
        );
    }

    /**
     * The three shapes a visible string takes in the chat template.
     *
     * Deliberately narrow and literal instead of "does this look German?".
     * A heuristic over prose would be the very mistake this suite keeps
     * documenting — a check that brings an assumption the subject does not
     * share. These three positions are where user-visible text actually goes;
     * each must resolve through `trans()` or the `LANG` object.
     */
    public function testTheChatTemplateHasNoHardcodedVisibleStrings(): void
    {
        $template = (string) file_get_contents(self::TEMPLATE);

        self::assertGreaterThan(1000, \strlen($template), 'template read as almost nothing');

        $patterns = [
            'placeholder attribute' => '/placeholder="(?!\{\{)[^"]+"/',
            'aria-label attribute'  => '/aria-label="(?!\{\{)[^"]+"/',
            'textContent assignment' => "/textContent\s*=\s*'[^']+'/",
        ];

        foreach ($patterns as $what => $pattern) {
            self::assertSame(
                0,
                preg_match_all($pattern, $template, $hits),
                \sprintf(
                    "%s with literal text — route it through TL_LANG:\n  %s",
                    $what,
                    implode("\n  ", $hits[0]),
                ),
            );
        }
    }

    /**
     * Control for the patterns above.
     *
     * Without it, "no hits" is indistinguishable from a pattern that matches
     * nothing at all — and these three had to be written by hand, so they are
     * exactly the kind that can be quietly wrong.
     */
    public function testThosePatternsWouldActuallyCatchSomething(): void
    {
        $sample = <<<'TWIG'
            <textarea placeholder="Frag den Agenten…" aria-label="Eingabe"></textarea>
            <script>button.textContent = 'Kopieren';</script>
            TWIG;

        self::assertSame(1, preg_match_all('/placeholder="(?!\{\{)[^"]+"/', $sample));
        self::assertSame(1, preg_match_all('/aria-label="(?!\{\{)[^"]+"/', $sample));
        self::assertSame(1, preg_match_all("/textContent\s*=\s*'[^']+'/", $sample));

        // And they must NOT fire on the translated form.
        $translated = '<textarea placeholder="{{ \'ai_chat.input_placeholder\'|trans({}, \'contao_ai_chat\') }}"></textarea>';
        self::assertSame(0, preg_match_all('/placeholder="(?!\{\{)[^"]+"/', $translated));
    }

    /**
     * Placeholders follow `sprintf`, not Symfony.
     *
     * 🔴 Found on the test server on 2026-09-05, one command short of a release.
     * The tool counter was written the Symfony way — `'%count% Werkzeuge'` with
     * `trans({'%count%': n})` — and every unit test passed. On a live install it
     * threw:
     *
     *     ValueError: The arguments array must contain 2 items, 1 given
     *     contao/core-bundle/src/Translation/Translator.php:57
     *
     * 🎯 Contao's translator does not substitute named placeholders. For any
     * domain beginning with `contao_` it reads `$GLOBALS['TL_LANG']` and runs
     * **`vsprintf($translated, $parameters)`**. `%count%` is therefore not a
     * name but two format specifiers, and the chat page would have died on
     * render.
     *
     * Nothing in the suite could see it: `BilingualLabelsTest` checked that no
     * literal strings remained, the language files were complete and identical,
     * and both keys resolved. The defect lived in the one step no test performs
     * — handing the string to the translator that actually runs.
     *
     * So: `%s` in the language file, a **list** in the template
     * (`trans([tools|length], …)`), never a named key.
     */
    public function testPlaceholdersUseSprintfSyntax(): void
    {
        $checked = 0;

        foreach (glob(self::LANG_DIR . '/*/ai_chat.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            // A named placeholder is anything of the form %word% — exactly what
            // Symfony uses and vsprintf cannot read.
            self::assertSame(
                0,
                preg_match_all('/%[a-zA-Z_][a-zA-Z0-9_]*%/', $source, $named),
                \sprintf(
                    "%s uses named placeholders; Contao's translator runs vsprintf: %s",
                    basename(\dirname($file)) . '/' . basename($file),
                    implode(', ', $named[0]),
                ),
            );

            ++$checked;
        }

        self::assertSame(2, $checked, 'expected one ai_chat.php per language');

        // Control: the pattern has to be able to fire.
        self::assertSame(1, preg_match_all('/%[a-zA-Z_][a-zA-Z0-9_]*%/', "'%count% Werkzeuge'"));
    }

    /**
     * The controller messages an editor reads in the chat.
     *
     * `errorResponse()` renders straight into the interface, so its second
     * argument may never be a literal. `$e->getMessage()` is allowed: those
     * come from `AiConfigException`, which is written elsewhere.
     */
    public function testControllerMessagesAreNotHardcoded(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Controller/AiStreamController.php');

        self::assertSame(
            0,
            preg_match_all('/errorResponse\(\d+,\s*\'[^\']+\'/', $source, $hits),
            "hardcoded message in errorResponse():\n  " . implode("\n  ", $hits[0]),
        );

        // Control: the pattern has to be able to fire.
        self::assertSame(1, preg_match_all('/errorResponse\(\d+,\s*\'[^\']+\'/', "return \$this->errorResponse(429, 'Zu viele Anfragen.');"));
    }
}
