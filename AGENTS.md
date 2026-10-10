# contao-ai-backend-bundle

A Contao back-end page (Contao 5.7 to 6.x): an in-browser AI agent for editors and admins. Built on
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

- **Agent-neutral.** `AGENTS.md` is the one guide for every coding agent; `CLAUDE.md`
  only imports it. Nothing here may depend on one coding agent: no agent-specific instructions,
  paths or file names in the guide, the tests or the code — the `CLAUDE.md` shim that
  only imports this file is the one exception.
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
- **Templates are Twig, in `contao/templates/`, rendered as `@Contao/…`.** No
  `.html5`, no `BackendModule` with `$strTemplate`, no Twig namespace of the
  bundle's own. Back-end pages are routes on Contao's `Controller\Backend\AbstractBackendController`
  with a `contao.backend_menu_build` listener for the menu entry — see
  `AiChatController` and `BackendMenuListener`. `ChatPageIsTwigTest` enforces it.

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

**Contao 6 does not read `.html5` templates.** Up to v0.9.3 the chat was a legacy
`BackendModule` with a `be_ai_chat.html5` wrapper. On Contao 6.0 it answered
*Template "@Contao/be_ai_chat.html.twig" is not defined* (issue #26). Nothing
here noticed: the 6.0 test installation is reachable from the console only, and
no test renders a back-end page. Since v0.10.0 nothing legacy is left.

**A subfolder of `contao/templates/` is part of a template's name only below a
`.twig-root` marker.** Without it Contao reads the top level only and drops the
folder: `backend/ai_chat.html.twig` was registered as `@Contao/ai_chat.html.twig`
and the controller's name did not resolve. A test that checked the file's
existence passed throughout; `ChatPageIsTwigTest` now asks Contao's own
`TemplateLocator`, not a copy of its rule. Do
not delete the empty marker file — it is the reason the names work.

> To see what Contao actually calls a template on an installation:
> `vendor/bin/contao-console debug:contao-twig <name>`. Believe that, not the
> file path.

**symfony/ai wraps every exception a tool throws** in its own
`Toolbox\Exception\ToolExecutionException`, unless it implements
`ToolExecutionExceptionInterface` — ours do not. Up to v0.10.0 the chat's catch
blocks for `access_denied`, `tool_refused` and `tool_failed` were therefore never
reached: every refusal read as `agent_failed` with a bug report. The tests that
guarded the branches read the controller's source and passed throughout.
`AiStreamController::runAgent()` unwraps ours (v0.11.0); a foreign exception stays
wrapped and is a defect. Since symfony/ai 0.14 a call of a registered tool the run's
`tools` option left out ends the run with `ToolNotFoundException` (#2602) — the same
exception the toolbox throws for a name no tool has; only the first is a refused
permission (`isRestrictedToolCall()`).

**The model's own mistakes go back to the model** (v0.12.0). `AgentFactory` wraps the
toolbox in `SelfCorrectingToolbox`: arguments that do not fit a tool
(`InvalidToolCallArgumentsException` — a missing parameter, a wrong type) and a tool name
no tool has come back to the model as the tool's result, with the user's tool names, and
the model calls again. Both used to end the chat as `agent_failed` with a bug report.
Everything else still ends the turn: a refusal, a denied permission and a crash belong
to the user. A registered name the toolbox cannot find is a lost tool object, ours to
fix, and stays an exception.

**A refusal and a crash from the core are told apart by `exception`** (core-bundle
v1.3.0): an error answer that carries it — or an entry of a bulk update's `errors` that
does — is `ToolExecutionException` (`tool_failed` with a report); without it, it stays
`ToolRefusedException`. An older core never sends the field, so everything stays a
refusal there, as before. An answer without `message` (a bulk update's `status:
partial`) is told from its `errors`: "2 von 3 nicht geändert — 7: …; 9: …"
(`AbstractCoreCommandTool::failureMessage()`).

> Anything about what reaches the chat is tested with a real agent:
> `AgentRunErrorsTest` runs `Agent`, `Toolbox` and `Runner` against
> `Symfony\AI\Platform\Test\InMemoryPlatform`, which scripts the model's answers —
> no API key, no cost. Add a probe tool there before believing a catch block.

**A refusal's text is not necessarily ours.** It is the core command's `message`,
and the core bundle's error boundary puts any exception's text there
(`DriverException: …` with SQL and parameters). The chat scrubs it like a failure
(`scrubMessage()`), only with more room.
