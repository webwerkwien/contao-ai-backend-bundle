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

**Node.js is a test prerequisite.** `tests/Unit/ChatMarkdown` runs the chat
page's Markdown renderer from the template with `node`; without it that test is
*skipped*, and the whole rendering and link-safety promise goes unchecked while
the run still says OK. If `composer ci` reports a skip there, install Node first.
Nothing on the chat page may parse a string as HTML — answers quote page content
nobody here authored; `testThePageParsesNoStringAsHtml` searches the raw template,
comments included, so don't name those calls in a comment either.

> ⚠️ **A PHPStan run that dies still prints a count.** Hitting the memory limit
> ends with `[ERROR] Found 2 errors` — a partial number that reads like a nearly
> clean result, because 2 is smaller than the real figure. The reason for the
> abort is printed *above* the number, so `tail` and `grep` show you the number
> and swallow the reason. Confirm the run finished before believing the count.

## Verifying your work

Run `composer ci` before reporting any task complete, and paste the output.
Read the result before committing — never run it and the commit in one chained
command; a red run commits anyway.

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

**Test against the current core-bundle.** There is no lock file in the repository, and a
`vendor/` that is never updated keeps whatever core it got: until 2026-10-12 it was
v0.6.0, and `ToolArgumentsMatchCommandTest` skipped every tool whose core command did not
exist there — silently. After a core release: `composer update webwerkwien/contao-ai-core-bundle`.

**What the chat does with a real model is a separate check: the chat runner**
(`tests/Chat`, since 2026-10-19). `scenarios.yaml` holds what editors ask and what must
follow — tools called or not, the records afterwards; `RunChatScenariosCommand` runs it on
an installation through the agent of the chat page, as a signed-in user (a real security
token: tool access, Contao's voters and field rights decide as on the page), on a fresh
fixture per scenario, and records every tool call with its arguments, which the page
never shows. It is not in the package; on the test server it is wired through the
project's `composer.json` (autoload of `chat-runner/`) and `config/services.yaml`:

```
php vendor/bin/contao-console contao-ai:chat-scenarios chat-runner/scenarios.yaml --ai-from <admin with an AI key> [--only id,…] [--no-alt] [--out file.json]
```

`--without-token` is the counter-check (every write must be refused), `--votes role:ref:field`
prints each voter's vote. Every scenario is a real call to the AI provider. Run it before a
release that changes what a tool takes or answers: the chat defects fixed in v0.19.2 and
v0.19.3 all passed the unit tests and showed only with a real model.

**For a bug fix, write the failing test first.** Reproduce the bug as a test, run
it, confirm it fails for the reason you expect, and commit that test before
touching the implementation. Do not edit test files while making the fix. A test
that existed before the fix, and that could not be rewritten, is the proof.

## Conventions

- **Agent-neutral.** `AGENTS.md` is the one guide for every coding agent; `CLAUDE.md`
  only imports it. Nothing here may depend on one coding agent: no agent-specific instructions,
  paths or file names in the guide, the tests or the code — the `CLAUDE.md` shim that
  only imports this file is the one exception.
- **No internals in the CHANGELOG** — nor in the GitHub release notes, which copy it: no names, no review or
  acceptance tags, no servers or hosts, no test data, no keys or key IDs. Only what changes for the people using
  the bundle.
  Removing internals is not announced either: nothing like "no longer refers to" or "removed internal
  references" — that says there were some, and the diff between the tags shows them. Done with a regular
  release, it goes unmentioned; on its own, the entry reads "Overhaul of code comments and the agent guide."
- **Public surface since 1.0** — see README *Stability*; a change there is a major.
  symfony/ai types never cross it (`AttachmentContent`, not `ContentInterface`): the
  provider library is pre-1.0 and moves under us, the extension points must not.
- **Attachments are checked in a fixed order, cheapest and most certain first.**
  `Content-Length` against `post_max_size` comes before the JSON is decoded: above it PHP
  drops the body, which would otherwise read as a missing CSRF token. Then
  `AttachmentReader`: count, name, duplicate, Contao's upload types, a converter, the
  base64 length *before* decoding (a 30 MB string is never decoded to learn it is too
  large), strict decoding, size, `finfo` against the extension, conversion, and whether
  the model reads the result. A file's content never reaches the history, the log or an
  error report — only its name, type and size.
- PHP 8.2+, `declare(strict_types=1)` in every file.
- Tools extend `AbstractCoreCommandTool` and are auto-tagged via `_instanceof`.
- **Every tool name needs an entry in `ToolAccessChecker::TOOL_MAP` and in
  `SystemPromptProvider::ALL_KNOWN_TOOL_NAMES`** (v0.19.1). A name without a map entry is
  admin-only by accident: `canUseTool()` refuses it for every other user, so the tool is
  neither offered nor callable. The event and FAQ tools sat there from v0.6.0 to v0.19.0.
  `ToolMapCoversEveryToolTest` compares both lists with the names `getToolNames()` reads.
- **Rights come from Contao, not from a list of our own.** Which tables a user reaches is
  `TableAccess` (BE_MOD plus `hasAccess($module, 'modules')`, the rule of Contao's
  `TableAccessVoter`); record rights are Contao's voters, field rights are `alexf` through
  `FieldPermission`. `ext_run`
  offers a foreign command only if every table of its `#[AiContract]` is reachable, none
  named means admin-only. Don't add a configuration allow-list beside it (decided with
  by design).
- **Every write the chat makes passes `FieldPermission`** (v0.17.0): `buildSetOptions($fields,
  $table)` for updates, `assertFieldsCreatable()` for the values a create sets (a field's
  default needs no right, as in the back end), `assertFieldsWritable()` for anything else
  that writes a field (`page_publish` writes `published`). Contao's rule, the same in 5.3
  to 6.0: a field is editable when it is not excluded or the user is granted it, and
  `DataContainer::isFieldExcluded()` counts every field with an input as excluded. The
  tool's own `allowedFields()` only narrows what the chat may write at all; it is not a
  right. Until v0.16.4 this line above said "field rights are `alexf`" while only
  `language_link` checked them — an editor whose group grants no page field could
  publish pages through the chat. A new write path without this call is that bug again.
- **Every record the chat touches passes `DataContainerAccess`** (v0.18.0): Contao's data
  container voters, `isGranted('contao_dc.<table>', <action>)`, asked with the action
  `DC_Table` builds — `assertCreate($table, $new)`, `assertRead`, `assertUpdate($table, $id,
  self::writtenValues($fields))`, `assertDelete`. Admins are asked too, as in the back end.
  **Never ask `contao_dc.*` through the global `isGranted()`:** these voters only deny or
  abstain and `DefaultDataContainerVoter` grants last, which needs the priority strategy;
  Contao applies it only inside its own firewalls, and the CLI bridge (`/_ai_cli/macro`)
  runs in none, where the global check allowed everything.
  `DataContainerAccess` decides with its own `AccessDecisionManager` and `PriorityStrategy`
  over the tagged voters.
  Mind three things the voters assume: the DCA is loaded (the service does it; otherwise
  `TableAccessVoter` treats every field as excluded and refuses editors without the right
  on all fields — its own comment says "abstains"), `sorting` in a create is non-null (null
  reads as a copy to the page voter), and an update with no values passes `null`, not `[]`
  (`[]` reads as "only moved", which needs no edit right). The content voters let any
  parent table pass that they do not know, so `ptable` from the model is limited for
  editors to the parents whose voter is installed
  (`DataContainerAccess::contentParentDenial()`). Until v0.17.0 every tool
  rebuilt a part of this by hand (module plus archive or page right), and admins skipped
  it; don't add such a rebuild back.
- **A tool parameter's `@param` type has no spaces** (`array<string,scalar|null>`, not
  `array<string, scalar|null>`). symfony/ai reads the description from `@param \S+ $name`;
  a space in the type hides the whole description from the model. Until v0.19.2 thirteen
  parameters lost theirs this way, among them `fields` of `news_update`, `event_update` and
  `faq_update` with their allowed field names and `modifications` of `record_clone` with
  its rules on languages —
  the model never saw any of it. `ParameterDescriptionsReachTheModelTest` checks every tool.
- **Write payloads go through `writeFields()`** (v0.19.2, `buildSetOptions()` calls it).
  The schema symfony/ai builds for `array $fields` is a list of scalars, so a model has no
  object for a headline's level: it sends `["headline", "Titel", "unit", "h2"]`. A bare
  `unit` becomes `<field>_unit` when one `inputUnit` field can be meant, a `{unit, value}`
  object becomes the JSON the core reads, any other object is refused (it used to arrive
  as the text "Array"). A tool that sends values to the voters first calls it before
  `writtenValues()`, as `ContentTool::update()` does.
- **A bridge's additions reach the package factory through `factoryArguments()`** (v0.20.0).
  Where `symfony/ai-<provider>-platform` is installed, `PlatformRegistry` builds the platform
  with the package's factory and the bridge's `createPlatform()` never runs. The model catalog
  addition for Claude 5.5 lived there first, passed a test that built the catalog itself, and
  failed with "No provider found for model". `ModelChoiceTest` builds the platform the
  way the chat does. The models a user may pick come from `ModelChoice` — the installation's
  `contao_ai_backend.models`, else the bridge's `getModels()` — and the stream controller
  refuses any other name from the browser.
- **A tool for an optional extension is offered only when its command exists**
  (`ConsoleRunner::has()`), not merely its classes: `LanguageTool` needs both
  changelanguage's `PageFinder` and the core's `contao:language:link` (core ≥ 1.4.0).
  `LanguageVersionsTool` needs `contao:language:versions` (core ≥ 1.11.0, registered only
  with changelanguage) — a tool of its own, so an older core hides it without hiding
  `language_link`.
- **The chat runs nothing that does not go through the DCA.** No
  tool starts a process, `ext_run` skips the core's `contao:` commands, and an extension
  command whose contract declares `irreversible` — read from
  `declared.irreversible_outside_database`, the place the core's `ContractPresenter`
  puts it, or found under `problems` — is not run at all, admins included (v0.22.1).
  Build test contracts with `ContractPresenter::present()`, never by hand: the hand-built
  shape is how the confirmation for such commands went missing for six weeks.
- **What the core names beyond the record asked for goes through the voters too.** Linked
  language versions and website roots (`content_find`, `language_versions`) are filtered
  by `FiltersLanguageVersions`: each entry a read of that record, the rest counted, an
  unknown shape dropped. The core trusts its operator; a title from outside the user's
  mounts must not reach the model.
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
permission (`isRestrictedToolCall()`). Since symfony/ai 0.15 the converters throw
`MaxOutputTokensException` when the model's answer, a tool call included, was cut at its
output limit; the chat names it `output_cut`, without a report — a limit, not a defect.
That limit is 1000 tokens for Claude unless set; `ResolvedPlatform::modelForRun()` sets
8192 as a model option in the name (`claude-…?max_tokens=8192`, v0.23.0) — use it, not
`->model`, wherever a run starts.
Both 0.14 and 0.15 are allowed; tests build platforms with `MockPlatformFactory`
(`InMemoryPlatform` is gone in 0.15).

**The model's own mistakes go back to the model** (v0.12.0). `AgentFactory` wraps the
toolbox in `SelfCorrectingToolbox`: arguments that do not fit a tool
(`InvalidToolCallArgumentsException` — a missing parameter, a wrong type) and a tool name
no tool has come back to the model as the tool's result, with the user's tool names, and
the model calls again. Both used to end the chat as `agent_failed` with a bug report.
Everything else still ends the turn: a refusal, a denied permission and a crash belong
to the user. A registered name the toolbox cannot find is a lost tool object, ours to
fix, and stays an exception. symfony/ai's denormalizer does not check an `array`
parameter — the six `*_update` tools take `array $fields` — so a string there fails as a
`TypeError` when the tool method is called (measured with symfony/ai 0.14.1). That one
goes back to the model as well, recognised by PHP's wording for exactly that method —
`<DeclaringClass>::<method>(): Argument #n (…) must be of type …` (`isArgumentTypeError()`).
A trace check is not enough: `\count()`, `strlen()` and the like run as opcodes without a
frame of their own, so a bug with them in the tool method's body also has the tool method
on top. Any other `TypeError` — inside our code, a wrong return type — stays a defect, and
the model gets our own sentence, not PHP's (which names the server path). Argument errors are
logged at `info`, not `warning` (`ToolCallLogger`): they are the model's mistake, not the
system's.

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
