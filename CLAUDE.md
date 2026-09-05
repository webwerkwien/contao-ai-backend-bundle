# contao-ai-backend-bundle

A Contao 5 backend module: an in-browser AI agent for editors and admins. Built on
`symfony/ai` and `webwerkwien/contao-ai-core-bundle`, which supplies the console
commands this bundle wraps as agent tools.

## Commands

```bash
composer ci        # the gate — PHPStan level 6 then PHPUnit, both must pass
composer phpstan   # static analysis alone
composer phpunit   # tests alone
```

`composer ci` needs no extra flags. The memory limit PHPStan requires and the
`allow-plugins` entry it needs are both in the repository — if the command asks
you for either, something is wrong with the checkout, not with your invocation.

> ⚠️ **A PHPStan run that dies still prints a count.** Hitting the memory limit
> ends with `[ERROR] Found 2 errors` — a partial number that reads like a nearly
> clean result, because 2 is smaller than the real figure. The reason for the
> abort is printed *above* the number, so `tail` and `grep` show you the number
> and swallow the reason. Confirm the run finished before believing the count.

## Verifying your work

Run `composer ci` before reporting any task complete, and paste the output.

Healthy output has two halves, PHPStan first and PHPUnit second:

```
 [OK] No errors

OK, but some tests were skipped!
Tests: …, Assertions: …, Skipped: 1.
```

The skip is expected. The counts are deliberately not written down here — they
change with every commit, and a documented figure would be wrong by the next one.
What must hold is the shape: `[OK] No errors`, then `OK`.

A failure is a failure — fix the code, never the test.

**For a bug fix, write the failing test first.** Reproduce the bug as a test, run
it, confirm it fails for the reason you expect, and commit that test before
touching the implementation. Do not edit test files while making the fix. A test
that existed before the fix, and that could not be rewritten, is the proof.

## Conventions

- PHP 8.2+, `declare(strict_types=1)` in every file.
- Tools extend `AbstractCoreCommandTool` and are auto-tagged via `_instanceof`.
- Code and comments in English.
- **Everything a user sees is bilingual, German and English.** Every key under
  `contao/languages/de/` has its counterpart in `en/`, and the two are kept in
  sync — adding a label means adding it twice. No user-facing string belongs in
  a template, a controller or a piece of JavaScript; it goes through `TL_LANG`.
  `BilingualLabelsTest` enforces both halves.
- Every scanning test needs a counter and at least one known non-match. A search
  that finds nothing passes exactly like one that finds everything.

## Things that go wrong here

**A `class_exists()` guard is only half of what plugin-conditional wiring needs.**

Tools that depend on an optional Contao bundle (`faq`, `calendar`, `news`) are
registered in `config/services_<plugin>.yaml`, imported by `loadExtension()`
behind a `class_exists()` check. That guard prevents the *additional*
registration. It does **not** take the class out of the `../src/`
auto-discovery in `services.yaml`.

Without an `exclude` entry there, the tool is built on every installation —
including those where its command does not exist — and the container fails to
compile:

```
Cannot autowire "…Tool\FaqTool": argument "$createCommand" needs
"…Command\FaqCreateCommand" but this type has been excluded
```

This shipped twice, in v0.6.0 and v0.7.0, and took a live site down with HTTP 500
on every domain. Fixed in v0.7.1.

> **So: registered conditionally and excluded from auto-discovery belong
> together.** Adding one without the other is the defect.

`tests/Unit/PluginConditionalServicesAreExcludedTest.php` **already enforces
this** — it is not something to write again. Extend it when a new
plugin-conditional service appears.

**Local test runs cannot see this class of defect.** `class_exists()` asks the
autoloader, so once an optional bundle sits in `vendor/` — and the dev
dependencies put all four there — the condition can no longer be false here. The
check has to work from the configuration, which is what the test above does.

**A visible string that does not look like translation work never enters the
translation process.** The language files here were kept carefully; the gap sat
in the places where the job at hand was something else — an HTML attribute, a
`textContent =` assignment in JavaScript, a flash message next to a type error,
a line of running text in a template. Eleven strings, found only because
unrelated work happened to touch those lines.

> When you write anything a user reads, the question is not "am I translating
> right now" but "will someone see this". `BilingualLabelsTest` covers the
> patterns that have actually occurred; it cannot cover the one nobody has
> thought of yet.

**Before using a service as a control in a test, check whether it has its own
entry under `services:` in `services.yaml`.** An explicit definition overrides
the exclude, so such a service reports "active" even when it is excluded — it
cannot fail, and is therefore useless as a control.
