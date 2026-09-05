# Changelog

All notable changes to this project are documented here. The project adheres to [Semantic Versioning](https://semver.org/) (within the pre-1.0 reservations).

## v0.8.0 — 2026-09-05

### Fixed

- **The chat interface was hardcoded German.** Input field, submit button, tool
  counter, the copy button on error reports, and five controller messages —
  eleven strings in all. On an English installation they showed German next to a
  module name and field labels that were translated correctly.

  `aria-label` was among them, which is what a screen reader announces.

  🎯 **The discipline held exactly where it was visible.** Nobody forgets a
  `$GLOBALS['TL_LANG']` entry — the file is called `languages/de` and its
  counterpart sits beside it. A string in a Twig attribute, a `textContent =`
  assignment or a flash message carries no such reminder, so it never entered
  the translation process at all. The gap was not where the work is; it was
  where the work did not look like translation.

  All eleven now live in `contao/languages/{de,en}/ai_chat.php`.
  `BilingualLabelsTest` enforces matching files, matching keys, and the absence
  of literals in the three places they actually occurred.

- **`AiCliTokenController` called `getFlashBag()` on `SessionInterface`,** which
  does not declare it. It worked because Symfony hands out a `Session`, and would
  have stopped the moment it did not. Now guarded by
  `FlashBagAwareSessionInterface`.

- **`TlUserCallback` received a CSRF token manager and token name it never
  read.** Left over from when this widget rendered its own `<form>` elements;
  since the switch to `<button formaction="…">` the outer DCA form carries
  Contao's `REQUEST_TOKEN`. The protection was never gone — it lives in
  `AiCliTokenController`, which enforces `assertCsrf()` on both routes — but a
  dead injection makes a reader look for the gap in the wrong place.

### Added

- **`composer ci` is green for the first time.** It was documented as the gate
  and had never passed: 56 findings, most of them missing setup rather than
  defects. PHPStan now runs on `^2.1` with a `phpstan.neon.dist`, and the four
  optional Contao bundles are dev dependencies.

  🎯 A verification command that has never passed is worse than none. It teaches
  everyone who runs it that red is the normal state, and that lesson outlives
  every later repair.

### The one a test could not have caught

The tool counter was first written the Symfony way — `'%count% Werkzeuge'` with
`trans({'%count%': n})`. Every unit test passed. On the test server it threw:

```
ValueError: The arguments array must contain 2 items, 1 given
contao/core-bundle/src/Translation/Translator.php:57
```

**Contao's translator does not substitute named placeholders.** For any domain
beginning with `contao_` it reads `$GLOBALS['TL_LANG']` and runs
`vsprintf($translated, $parameters)`, so `%count%` is not a name but two format
specifiers — and the chat page would have died on render.

Nothing in the suite could see it: the language files were complete and
identical, both keys resolved, no literals remained. The defect lived in the one
step no test performs — handing the string to the translator that actually runs.
Now `%s` plus a list, and `testPlaceholdersUseSprintfSyntax` fails on any `%word%`
in a language file.

## v0.7.1 — 2026-09-04

### Fixed

- **The container build failed on installations without contao/faq-bundle or
  contao/calendar-bundle.** ⚠️ **v0.6.0 and v0.7.0 are affected — update.**

  ```
  Cannot autowire "…Tool\FaqTool": argument "$createCommand" needs
  "…Command\FaqCreateCommand" but this type has been excluded
  ```

  `EventTool` and `FaqTool` arrived in v0.6.0 and were registered in
  `services_calendar.yaml` / `services_faq.yaml`, guarded by `class_exists()` in
  `loadExtension()`. What was missing is the other half: excluding them from the
  `../src/` auto-discovery in `services.yaml`.

  🎯 **The guard only prevents the *additional* registration — it does not take
  the class out of auto-discovery.** Without an exclude entry the tool is built
  everywhere, including where its command does not exist, because the core bundle
  excludes that command by the same mechanism. The guard looks sufficient; it is
  exactly half of what is needed.

  Found on a live site during an update, not here: the test server has both
  bundles installed, so the container built and every test passed. The same site
  supplied the control — `contao/news-bundle` is missing there too, and
  `NewsTool` stayed silent, because it *was* excluded. Same shape, opposite
  outcome, one line of configuration apart.

### Added

- **`PluginConditionalServicesAreExcludedTest`** derives the rule from the
  configuration instead of restating it: every class registered in a
  `services_<plugin>.yaml` must be excluded in `services.yaml`. Verified against
  the real defect — with the two lines removed it names exactly `EventTool` and
  `FaqTool`. It grows on its own with the next plugin-conditional service.

## v0.7.0 — 2026-09-04

### Added

- **Failed chat turns carry a forwardable error report.** `tool_failed` and
  `agent_failed` now emit a `report` field alongside `message` — a Markdown block
  with versions, exception class, our own file and line, and the tool that was
  running. The chat UI shows it collapsed under the error, with a copy button.

  `access_denied`, `tool_refused` and the 412 config error get **no** report.
  Those are answers, not defects: a permission working as designed, a command
  that looked and declined, a setup that is incomplete. Attaching "report this to
  the maintainer" to them would train users to send noise and bury the two cases
  that are genuinely ours.

  That line is not new — it is the 422/500 split drawn on 2026-09-02 for the
  bridge's status codes, which turns out to be exactly the line between bug and
  not-a-bug. `ErrorReportOnlyForRealFailuresTest` keeps the two from drifting
  apart, because nothing else would notice: adding `'report'` to the refusal
  branch breaks nothing, it just quietly makes every mistyped id look like a
  defect.

- **The audience is decided server-side.** An admin receives the full report
  including the masked exception message; anyone else receives the summary with
  the message stripped. H-6 — the raw exception never reaches the client — keeps
  holding unchanged for exactly the audience it was written for. An admin may
  read it because the same person can open `var/logs/prod-*.log`; withholding it
  there would be theatre.

  An earlier draft had the browser fetch the full report on demand, so the click
  would *be* the consent. That needs the failure to outlive the request — a
  store, which the design set out not to build. Deciding at emit time is both
  cheaper and stronger: an editor never receives the message, rather than being
  trusted not to ask for it.

### Changed

- **`CredentialMasker` now comes from contao-ai-core-bundle.** The class moved
  there in core v0.5.0 so the report builder could use it without duplicating the
  pattern list. Behaviour is unchanged; only the namespace differs
  (`Webwerkwien\ContaoAiCoreBundle\Service\CredentialMasker`).

  ⚠️ Breaking for anyone importing `Webwerkwien\ContaoAiBackendBundle\Service\CredentialMasker`.

- Requires contao-ai-core-bundle **>=0.5.0**.

### Fixed

- **README listed three `error` kinds, not four.** `tool_refused` has existed
  since v0.5.0 and was missing from the streaming section — a client written from
  that documentation would have treated a refusal as an unknown event.

## v0.6.0 — 2026-09-02

### Added

- **CRUD tools for calendar events and FAQ entries** — `event_create`,
  `event_update`, `event_delete`, `event_read` and the same four for `faq_*`.

  The gap was never a decision. CRUD tools shipped with v0.1.0 for page,
  article, content and news; calendars and FAQs only ever arrived through the
  cloner and the rewriter in phase 9. So the agent could *clone* a calendar and
  *rewrite* an event, but not create or update one.

  Cheap to close because the expensive half was already there: the console
  commands exist in contao-ai-core-bundle, and `RecordPermissionChecker` and
  `TABLE_MODULE` have covered both table pairs since the cloner was built. The
  allow-listed fields were read from the live DCA rather than copied from the
  news list — `recurring`/`repeatEach` are deliberately excluded, being
  serialized structures that a plain `--set` would fill with a string.

### Fixed

- **Calendar and FAQ permissions denied every non-admin.** `hasAccess($field,
  $array)` reads `$this->$array` on the user, and the property is named by the
  DCA's `userRoot`: `calendars` and `faqs`, in the plural. The checker asked for
  `'calendar'` and `'faq'` — properties that do not exist — so the check fell
  through and refused. It failed closed, so nothing leaked; but an editor with
  legitimate rights could not reach calendars or FAQs at all.

  Found while building the new tools, by reading Contao's own DCA instead of
  copying the existing call.

  All six checks now go through the `contao_user.*` voters, which is the route
  `RecordListTool` already took — and the one that survives: `hasAccess()` is
  deprecated since Contao 5.2 and says of itself that it *"will no longer work
  in Contao 6"*.

- **Fifteen more places answered HTTP 500 for a missing record.** v0.5.0 changed
  the branch that evaluates a console command's *result* and reported the finding
  closed. But the tools throw "nicht gefunden" **directly**, before any command
  runs — six files, still 500 after the release meant to end exactly that.

  A fix verified at one call site and generalised into a claim. A test now checks
  the shape instead: a message telling the caller their record does not exist can
  never be an execution failure, wherever it is thrown.

### Changed

- **A `null` argument to `runCommand()` now means "not passed".** It used to
  reach `ArrayInput` as an option without a value, so every `create` assembled
  its optional argument in a variable first:

      $args = ['--headline' => $headline];
      if (null !== $date) { $args['--date'] = $date; }

  🎯 That is precisely how they escaped `ToolArgumentsMatchCommandTest`, whose
  scan reads the array literal **inside** the call — so `news_create` had never
  been checked against `NewsCreateCommand` since the day it was written, in the
  very test built after `page_publish` shipped broken for want of that check.

  Found by mutation while adding `EventTool`: `--title` was renamed to `--titel`
  and the suite stayed green. Dropping nulls lets the keys stay inline, which
  puts them back in front of the checker — 26 checked calls became 29, and the
  mutation now fails as it should.

## v0.5.0 — 2026-09-02

A minor rather than a patch: an HTTP status code is a return contract, and one
of them changed.

### Fixed

- **A record that does not exist answered HTTP 500.** Every failure of an
  underlying console command became `ToolExecutionException` and therefore a
  server error, so a typo in an id was indistinguishable from a crash:

      bridge clone --table tl_faq_category --source-id 1
      -> HTTP 500: Tool "record_clone" fehlgeschlagen: FAQ-Kategorie 1 nicht gefunden.

  Refusals now raise `ToolRefusedException` and answer **422**, joining the
  existing `\InvalidArgumentException` branch. The line is drawn at *whether the
  command answered at all*, which needs no matching on error text: a structured
  `{"status":"error","message":…}` means it ran, understood the request and
  declined; no JSON, an unusable shape or an exception means it could not
  answer, and stays 500.

  🎯 **This mattered because something had just started reading that code.**
  contao-ai-cli v0.15.0 stopped treating a 500 from `bridge configure --test` as
  "auth OK" and began reporting `auth_ok_server_error` — *"your token works, the
  bridge is broken"*. With a mistyped id producing the same 500, that message
  would have accused a healthy bridge. One status number carrying two meanings
  is harmless until it is used.

  ⚠️ The trade-off, stated rather than hidden: a genuine server-side failure
  (database gone, disk full) can also arrive as a structured error and will now
  be reported as 422. The alternative was matching on German error text, which
  fails silently the first time a message is reworded. The message travels
  either way.

  In the back end chat the same refusals now carry `kind: "tool_refused"`
  instead of `tool_failed`. **Two controllers caught the old exception** — a fix
  that only touched the bridge would have made chat refusals fall through to
  `agent_failed`, a missing record reading as a crashed agent. A test derives the
  set of catchers rather than listing them, so a third one cannot be forgotten.

### Changed

- **German keywords for the Contao extension index.** Both bundles are listed at
  `extensions.contao.org` (`discoverable: true`), but were findable only through
  English terms: `agent`, `automation`, `claude` and `crud` matched, while `KI`,
  `Redakteur`, `ssh` and even `contao-ai` — the product's own name — returned
  nothing. Other extensions in the index are found through their German
  descriptions; these were not.

## v0.4.0 — 2026-09-02

Security and correctness fixes. Every item below was reproduced before it was
fixed. Released together with contao-ai-core-bundle v0.4.0, which carries the
findings from the same audit on the write path and the cloners.

**Requires contao-ai-core-bundle >= 0.4.0.** `record_clone` injects that bundle's
`RecordCloneCommand` directly, so on an older core the tool still drops the
content elements under cloned news and events and still writes duplicate
aliases — silently, and answering `ok`. The constraint was raised from
`>=0.2.38` for that reason and not as housekeeping.

### Fixed

- 🔴 **A stored API key could be sent to a provider the user never chose.** When
  the selected platform was not installed, the configuration silently fell back
  to `anthropic` **and kept the key** — so `composer remove
  symfony/ai-mistral-platform` was enough to make the next chat hand a Mistral
  key to api.anthropic.com, with one log warning and no error for the user. The
  provider is never substituted now: an unknown value is passed through and
  refused by name. Introduced in v0.3.0, where deriving the list made the path
  reachable through ordinary package management.

- 🔴 **`record_rewrite` served two providers while the dropdown offered six.** It
  still resolved platforms through the two hand-written bridge classes and
  demanded an API key unconditionally, so it refused Ollama for lacking a key it
  does not need, answered *"unknown platform"* for every derived provider, and
  ignored `ai_model` and `ai_base_url` entirely.

- 🔴 **The chat UI hid itself from every self-hosted provider.** It gated on "an
  API key is stored", which is the wrong question for Ollama or LM Studio — the
  case the derived registry exists for. It now asks whether the profile can run
  and names what is missing.

- 🔴 **A stored endpoint was discarded without a word** for providers whose
  factory has no such parameter (OpenAI among them). A user could enter a
  gateway address, save without error, and have every request — key included —
  go to the provider's own endpoint anyway. Both directions are now refused with
  a message instead of ignored.

- **The credential mask covered three key shapes out of seven**, missing OpenAI's
  current `sk-proj-…` form among others, and existed as two hand-copied lists
  with no test. It is now one service that strikes the literal key value —
  which also covers opaque keys no pattern can anchor on — with patterns kept as
  a net for secrets the bundle does not hold. The full exception no longer
  reaches the log unmasked.

- **A bridge whose factory needs a parameter this bundle cannot supply is no
  longer offered**, and the reflected call is wrapped: an upstream signature
  change surfaces as a sentence rather than an HTTP 500. Bridges with nothing to
  configure per user (Bedrock, TransformersPHP) are skipped for the same reason.

- **The endpoint field is no longer editable by a user on their own profile.**
  `ai_base_url` decides where the server sends HTTP requests, and it sat in the
  palette every backend user reaches through *Personal data* — so any editor
  could point it at an internal address. It is now only in the palettes an
  administrator uses to edit a user.

  Contao's own field permissions (`exclude` + *allowed fields*) do not help
  here: on the own-profile page Contao sets `exclude = false` for every field in
  the palette, deliberately, so nobody needs an administrator to change their
  own name. The palette is the only lever — and the right one, since an endpoint
  is an infrastructure decision, not a personal preference. Personal API keys
  stay where they were.

- 🔴 **A destructive action could execute without the user ever seeing the
  question.** The confirmation gate's entire enforcement was a sentence
  addressed to the model — *"ask the user, then call the same tool again"* — and
  since `symfony/ai` 0.13 the agent drives the tool loop itself. Two calls in one
  request were enough: the first staged the deletion, the second consumed it. A
  staged action is now bound to the HTTP request that created it and cannot be
  consumed before a new one, so a human must have sent something in between.

- **`record_list` returned records the same user cannot see in the back end.**
  For calendars, calendar events, FAQs, FAQ categories and files only the
  *module* was checked, on the assumption — written in a comment — that this was
  enough. It is not: Contao gates individual calendars and categories through
  `tl_user.calendars` / `tl_user.faqs`, the way it gates news archives. Those
  tables are filtered per record now, and an unknown table returns nothing
  instead of everything.

- **`record_rewrite` sent record content to the AI provider before checking
  whether the user may use the tool at all.** The record permission was checked
  up front, the tool permission only inside the write command — after the
  request had gone out. Permission now precedes the side effect, and an outbound
  request to a third party is one.

- **`dca_schema` did not check the backend module** its own tool description
  promised (*"a table the current user has module access to"*).

- **Tool call arguments were written to the log unmasked**, so a value a user
  typed into a field — an API key pasted into a text field, for instance — was
  stored in full at warning level.

- **Rewriter output went into the database unvalidated.** Plain-text fields now
  reject a result containing tags rather than quietly stripping them: markup in a
  headline is a signal, not a formatting slip. Rich-text fields are filtered
  through Contao's own `allowedTags`/`allowedAttributes` rather than a second
  list maintained here.

- **The sentinel wrapper around tool output cannot be forged.** `<` and `>` are
  now escaped in the JSON payload. The closing tag was already unreachable — but
  by accident, through slash escaping, and the *opening* tag was not.

- **Every skipped package is logged with its reason.** Previously a rename
  upstream — `PlatformFactory` became `Factory` in symfony/ai 0.13 — would have
  emptied the dropdown in production without a single log line.

### Known limitation: prompt injection

Content the agent reads can try to instruct it. This bundle wraps tool output in
a sentinel, truncates free-text fields and tells the model to treat the contents
as data — and none of that is isolation. A language model has one input channel,
and markup in it is a convention, not a boundary.

What the release does change is the blast radius:

- destructive operations require a real user turn between question and execution
- the sentinel can no longer be forged from record content
- an injected instruction can never exceed the rights of the user whose session
  is running; every tool re-checks them per record

**What remains:** an injected instruction can cause a non-destructive change the
user is entitled to make but did not intend — a title rewritten, a field
updated. Every such change is versioned in `tl_version` under the acting user, so
it is visible and revertible, but it is not prevented.

**For operators:** be deliberate about pointing the agent at tables that accept
input from outside the editorial team — form submissions, comments, imported
feeds. Reading those with a write-capable agent is the case this limitation is
about.

### Changed

- **README: the security section no longer claims API keys are encrypted.** They
  are not, and cannot be: the Contao DCA `encrypt` flag was removed with Contao
  5.0 and the flag sat in the DCA doing nothing. Keys are stored in plain text —
  treat database read access as equivalent to holding every key in it. The dead
  flag is gone from the DCA.

## v0.3.0 — 2026-09-02

> **Run `contao:migrate` after updating.** Two columns are added to `tl_user`
> (`ai_base_url`, `ai_model`), both `NOT NULL DEFAULT ''`. No existing data is
> touched or moved.

### Added

- **The provider list is derived from what is installed, not maintained.** Until
  now the DCA carried `'options' => ['anthropic', 'openai']` while symfony/ai
  shipped 37 bridges — the two-provider limit was ours, not the library's.
  Installing a package is now the whole procedure:

  ```bash
  composer require "symfony/ai-mistral-platform:^0.13"
  ```

  and Mistral is in the select. The registry reads the installed
  `symfony/ai-*-platform` packages through `Composer\InstalledVersions`, takes
  each one's namespace from its own `composer.json`, and reflects over
  `Factory::createPlatform()` to learn what it needs. Nothing to register.

- **Five providers ship with the bundle:** Anthropic, OpenAI, OpenRouter
  (~538 models through one key), Ollama and Generic. The last two are the point
  of the exercise — a self-hosted model means no customer content leaves the
  machine, and the previous interface could not express a provider that wants a
  host and no API key. Further bridges are listed under `composer suggests`.

- **`ai_base_url` and `ai_model`** on `tl_user`. Whether either is required is
  read from the chosen provider's signature rather than hard-coded: Anthropic
  demands a key, Ollama does not, Generic demands an endpoint.

### Changed

- An API key is only demanded from providers that actually take one. A
  self-hosted provider previously failed the check that was meant to protect it.
- `ai_platform` values are validated against the registry instead of a literal
  pair, so a provider whose package was removed stops validating the same day
  rather than failing later inside the factory.
- Dropped `ai_platform_ref` from both language files — a second list of provider
  names would have left every newly installed one unlabelled.

### Notes

Two details a hand-written list would have got silently wrong, both found by
measuring rather than assuming: Ollama calls the parameter `endpoint` where
every other bridge calls it `baseUrl`, and `name` in a factory signature is not
a model but the bridge's own canonical key — the value already stored in
`tl_user.ai_platform`, which is why none of this needs a data migration.

## v0.2.0 — 2026-09-02

> **Behaviourally identical to v0.1.6 — the number is the fix.** v0.1.6 carried the
> `symfony/ai` 0.7 → 0.13 move under a *patch* number, which would have handed a
> six-minor pre-1.0 dependency jump to every site constrained to `^0.1.x` — the very
> constraint those sites use in order *not* to receive that. v0.1.6 stays published;
> this is the release the change belongs to.
>
> **If you are on `^0.1.x`:** widen to `>=0.1 <1.0` (or `>=0.2 <1.0`) to receive this
> and later releases. Nothing breaks if you don't — you simply stay on 0.1.6.

### Changed

- **`contao-ai-core-bundle` is now required as `>=0.2.38 <1.0`**, not `^0.2.38`. In the
  0.x series Composer's caret caps at the *next minor*, so a core `0.3.0` would have been
  unreachable from here — the two bundles could only ever move in lockstep, and a release
  that did not arrive would have raised no error anywhere.

- **Installation instructions give an explicit constraint.** A plain `composer require`
  pins a 0.x package to its current minor. The Contao Manager applies the same default,
  but does show newer versions and lets you edit the constraint by hand.

- **README: removed what duplicated `composer.json`.** The dependency versions listed
  there had gone stale — `symfony/ai-bundle` still read `^0.7`, five months after the
  fact — and the frozen test counts (`151 tests, 252 assertions`) described a suite that
  has since grown past 450. A second place for the same information does not get
  maintained, only quoted.

## v0.1.6 — 2026-09-02

> **Needs contao-ai-core-bundle v0.2.38.** Upgrades `symfony/ai` from 0.7 to 0.13 —
> if you pin that stack yourself, check before updating.

### Fixed

- 🔴 **`page_publish` had never worked, in either direction.** The tool sent
  `--published=1|0`, and `contao:page:publish` takes two positional arguments — `id` and
  `publish`/`unpublish`. Every call failed with *The "--published" option does not exist*.

  It went unnoticed because nothing had ever reached it: no test covered the tool, and the
  first person to try unpublishing from the chat was the first person to find it.

  A new test now reads both sides from source — the argument arrays in the tools, the
  `addArgument`/`addOption` names in the command classes — and fails on a mismatch here
  instead of in somebody's chat window. `runCommand()` already asked `hasOption()` before
  adding `--operator`; that check existed for exactly one key and for no other.

- 🔴 **Unpublishing a page ran without confirmation**, while `AbstractCoreCommandTool`
  stated in its own docblock that the gate covers *"delete, unpublish"*. The promise was
  there, the call was not, and forty tests covered everything except this.

  `page_publish` now asks before taking a page offline. Publishing is not gated: it adds
  something the owner can see and undo in the same breath.

  A guard test keeps the list honest — anything that reads like a removal must be either
  gated or excused with a reason. The gate is deliberately *not* derived from
  `#[AiContract]`: by that vocabulary `irreversible` means an effect outside the database,
  and a page delete lands in `tl_undo`. Confirmation is this bundle's UX policy, not a
  statement about the operation, and putting it in the contract would tailor the contract
  to one consumer.

### Changed

- **`symfony/ai` 0.7 → 0.13.** Three call sites: `PlatformFactory::create()` became
  `Factory::createPlatform()` in both bridges, and `Toolbox\AgentProcessor` is gone — the
  Agent drives the tool-calling loop itself and takes the toolbox directly. Tool calls are
  bounded by default now (`maxToolCalls: 50`).

  A fourth change the class-level check could not see: `ToolCallSucceeded::getMetadata()`
  became `getDefinition()`. Every imported class survived the jump; one method did not.
  **Class existence is not API compatibility** — the tests caught it.

  Verified live on the test server: an agent built, both platforms instantiated, a real
  tool call executed from the chat with confirmation, and `tl_log` carrying both the
  command and the tool entry.

### Added

- **All 22 tools declare an `#[AiContract]`** — what they write, which tables, what trail
  and when. Read per method through `ContractReader`, which is why the core bundle now
  allows the attribute on methods: `#[AsTool]` sits above the class here and names its
  method by argument, so a class-level contract would make a read claim to write.

  Our own write commands honestly declare `traceWhen: 'on-success'`: `logSuccess()` runs
  from `outputSuccess()`, so a run that fails halfway leaves no trace of having started.
  The weaker assurance, stated rather than glossed.

## v0.1.5 — 2026-08-29

### Fixed

- **The `record_clone` tool description told the model the opposite of what the server does.** It read *"unknown fields are silently dropped"*, which stopped being true with [contao-ai-core-bundle v0.2.15](https://github.com/webwerkwien/contao-ai-core-bundle/releases/tag/v0.2.15): refused overrides are now listed back under `ignored_modifications`. A tool description is what the model reasons from, so it was actively teaching Claude not to check a key that had just been added for exactly this purpose.

  The description now names the accepted fields per table, points at `ignored_modifications`, mentions that `alias` is never taken from the payload, and states how to clone without publishing. `published` and `hide` became available for `tl_page` in the same core release; the description had no way of knowing.

  Nothing else was needed on this side: `runCommand()` decodes the payload without filtering keys and `postProcessDecoded()` only reads `id`, so the new field already reached the model — it was just being contradicted.

## v0.1.4 — 2026-08-25

### Fixed

- **The system-log entry for a tool call did not say which tool.** `ToolCallLogger` put the tool name in the Monolog context, which is fine for `var/logs` but useless in `tl_log`: Contao's `ContaoTableHandler` formats with `LineFormatter('%message%')` and drops the context entirely, so the back end showed nothing but *"contao-ai-backend tool requested"* — three near-identical rows per chat turn, none of them answering what happened. The tool name is now part of the message, and a failed call also names the exception class.

  Surfaced while adding `tl_log` support to [contao-ai-core-bundle v0.2.11](https://github.com/webwerkwien/contao-ai-core-bundle/releases/tag/v0.2.11), which is where the `%message%` formatter was first read carefully.

### Added

- 4 unit tests for `ToolCallLogger` — the three event messages and that `getToolNames()` still tracks the request. Suite now at 40 tests.

## v0.1.3 — 2026-08-13

### Changed

- **`AbstractEntityRewriter` replaces six copies of the same machinery.** `rewriteField()`, `resultToText()` and `isPlausible()` lived once per rewriter, differing only in a byte cap and per-field wording. That is how the refusal-pattern fix could reach three of six copies unnoticed (issue #1). Subclasses now supply `maxResultBytes()`, `fieldShape()`, and optionally `preservesHtml()` / `formFactorHint()`; `supports()` and `rewrite()` stay per-entity. 1085 lines across the six classes drop to 535 plus a 177-line base, and both traits are inherited by construction rather than by remembering to add them.
- The three HTML-carrying rewriters now share one wording (*"tag structure and attributes"*); `ArticleRewriter` and `FaqRewriter` previously said only *"tag structure"*.

### Fixed

- `resultToText()` returns an empty string for a result object exposing neither `asText()` nor `__toString()`, instead of raising *"Object of class … could not be converted to string"*.

### Added

- 15 unit tests for the shared machinery — provider result shapes, the plausibility thresholds (byte cap, refusal, truncation ratio, short-source exemption) and prompt assembly. Suite now at 36 tests.

## v0.1.2 — 2026-08-13

### Changed

- `export-ignore` keeps development-only files out of the distributed package — `composer require` no longer pulls the test suite and PHPUnit configuration into the consumer's `vendor/`.
- Corrected the `.gitattributes` header comment: `text=auto eol=lf` normalises line endings but does **not** strip a UTF-8 BOM, which the previous wording implied.

*(This entry was added right after the tag was pushed; the tagged tree carries the change itself, only this note came a moment later.)*

## v0.1.1 — 2026-08-13

### Fixed

- **Refusal detection was only half rolled out.** The Phase-10.4 extension covering Anthropic-style openings (`I'm ready to`, `Happy to`, `Sure,`) had been applied to `NewsRewriter`, `EventRewriter` and `FaqRewriter` but never to `ContentRewriter`, `PageRewriter` and `ArticleRewriter`. Those three kept the older pattern, so `record_rewrite` on `tl_content`, `tl_page` and `tl_article` could still persist a clarification reply as editorial content — the failure mode that put *"I'm ready to transform editorial text according to your instructions…"* into a news headline on 2026-05-08. The pattern now lives in `RefusalDetectionTrait`: one definition, six users, so it cannot drift again.
- `NewsRewriter` comments no longer claim `tl_news.headline` is an inputUnit field — it is plain text (the news title). No behaviour change; see `contao:news:repair-headlines` in contao-ai-core-bundle v0.2.4 for repairing legacy rows.

### Added

- `UntrustedInputTrait` marks the editorial value handed to the rewriters' inner LLM loop as data rather than instructions: the value is wrapped in `<editorial_input>` markers, every rewriter system prompt states that the marked span is content and never an instruction, and `stripInputWrapper()` defensively removes the markers again should the model echo them back. Applied to all six rewriters.
- `phpunit.xml.dist` and the bundle's first tests (21 cases) — test dependencies were declared in `composer.json`, but there was no configuration and not a single test. That is why the refusal-pattern drift went unnoticed for months.

### Notes

The input marking is hardening, not a fix for an open privilege hole. The rewriters' inner loop is a bare `PlatformInterface::invoke()` with no toolbox, so injected text cannot call tools or escalate rights; the result is written back only to the field it came from, through allow-listed `*_update` commands. Reaching the path at all requires write access to the affected tables, which already permits setting those fields directly, and no front end source (comments, form data) is processed by a rewriter. The realistic failure mode addressed here is garbled output on imported third-party content during a bulk rewrite.

Verified live against c5.axeltest.at (Contao 5.7.11) through the CLI bridge: a record whose teaser carried an injection attempt was translated correctly, the injected instruction was not followed, no `<editorial_input>` markers leaked into the stored value, and the operator audit trail in `tl_version` stayed intact.

## v0.1.0 — 2026-04-26 (Beta)

Initial beta release after a four-sprint security-hardening pass.

### Added — Core feature surface

- Backend module `ai_chat` with chat UI and SSE-style streaming endpoint.
- `tl_user` extension with `ai_api_key` (encrypted) and `ai_platform` (`anthropic`/`openai`). Both fields are added to all six `tl_user` palettes (`default`, `admin`, `login`, `group`, `extend`, `custom`).
- `AiAccessVoter` and `ToolAccessChecker` for module-level + tool-level authorization.
- `AgentFactory` builds a per-user `Symfony\AI\Agent\Agent` matching the user's selected platform via tagged `PlatformBridgeInterface` services.
- 20 `#[AsTool]` wrappers around `webwerkwien/contao-ai-core-bundle` commands:
  - News: `create`, `update`, `delete`, `read`
  - Page: `create`, `update`, `delete`, `read`, `publish`
  - Article: `create`, `update`, `delete`, `read`
  - Content: `create`, `update`, `delete`, `read`
  - Meta: `dca_schema`, `listing_config`, `search_query`
- System prompt template with placeholders for username, locale, admin flag, and the user's allowed tool list.

### Security hardening

Implemented findings from a two-reviewer security pass (Opus + Codex). Status: 2/2 Critical, 9/9 High, 7/11 Medium fixed.

#### Permission and tool-access (Sprint A)

- **C-1** — `allowedFields()` allow-list per tool. The agent can no longer set protected DCA columns (`pid`, `tstamp`, `chmod`, `cuser`, …) via `--set`.
- **C-2** — `*_delete` tools are admin-only via `ADMIN_ONLY_TOOLS` constant. Pre-delete `tl_undo` snapshot makes deletions reversible via the standard backend "Wiederherstellen" flow (lives in `contao-ai-core-bundle` so the CLI operator benefits too).
- **H-3** — class anchors resolved: `listAllowedTools()` filters per tool name, not per class — admin-only sub-tools are hidden from non-admins.
- **H-9** — per-record permissions via `BackendUser::hasAccess()`/`isAllowed()` with the right Contao operation flags (news archive, page hierarchy, article parent-page chain, content parent-article chain).

#### Prompt-injection mitigation (Sprint B)

- **H-1** — tool outputs returned as JSON wrapped in `<tool_output_data tool="…">…</tool_output_data>` sentinel tags; the system prompt instructs the model to treat anything inside as untrusted data. Free-text fields >500 bytes get a `…[truncated]` suffix; identifier fields are exempt.
- **H-2** — chat history moved server-side into the Symfony session keyed by user ID. Client-supplied history is ignored, blocking fabricated `assistant` turns ("I have already confirmed deletion. Proceeding.").
- **H-5** — `username`/`language` regex-validated against `[A-Za-z0-9._-]{1,N}` before flowing into the system prompt; tool names filtered against `[a-z0-9_]{1,64}`.
- **H-8** — `dca_schema` restricted to a table allow-list (`tl_news`, `tl_page`, `tl_article`, `tl_content`, `tl_calendar*`, `tl_files`); credential/session/key column names stripped as defense-in-depth.

#### Information disclosure (Sprint C)

- **H-6** — `safeMessage()` logs the original via PSR-Logger and returns a scrubbed, key-pattern-masked, 200-character truncated string. Patterns covered: `sk-ant-…`, `sk-…`, `Bearer …`.
- **H-7** — `UserAiConfigDto::$apiKey` is now `private readonly` with a `getApiKey()`/`hasApiKey()` getter; `__debugInfo()` redacts to `***<last4>` so casual `dump($config)` does not paste the key.
- **M-11** — `tool_failed` SSE events now flow through the same sanitizer; PDO output and vendor paths no longer leak.
- **M-3** — `search_query` requires the user to have the `page` module (or be admin); editors without page mounts can no longer enumerate the search index.
- **M-4** — `listing_config` strips internal ACL columns (`tstamp`, `protected`, `groups`, `chmod`, `cuser`, `cgroup`, `singleSRC`, `imgSize`).
- **M-2** — audit trail uses the actual Contao username instead of `$_SERVER['USER']`. Core bundle accepts a new optional `--operator` option on every write command; the backend wrapper injects it automatically when the command supports it. CLI invocations still fall back to the shell user.

#### Hardening (Sprint D)

- **M-5/M-6** — response headers: `Cache-Control: no-store, private, max-age=0`, `Vary: Cookie`, `X-Robots-Tag: noindex, nofollow`, explicit `charset=utf-8` on both SSE and JSON error responses.
- **M-7** — history caps: 4 KB per entry, 64 KB total. Oldest entries are dropped when either limit is exceeded.
- **H-4** — removed the `WeakMap` cache in `UserAiConfig`. Reads are now direct from the `BackendUser` model, eliminating the TOCTOU window when an API key is rotated mid-session.
- **L-2** — platform string re-validated against `['anthropic', 'openai']`; a stray DB value falls back to the default rather than wiring an undefined bridge.
- **M-1** — session-backed sliding-window rate limit: 30 requests/minute and 500/day per user → HTTP 429. No new dependency.
- **M-8** — `Origin` and `Sec-Fetch-Site` request headers verified against the host as defense-in-depth alongside CSRF.

### Deferred

These were considered and explicitly deferred — see `2026-04-26-contao-ai-backend-security-findings.md` in the project notes for rationale:

- M-9 (CSRF-token rotation) — M-8 covers most of the same threat at lower cost.
- M-10 (concurrent-session lock) — PHP-FPM session lock already serializes per session.
- L-1, L-3, L-4, L-5, L-6, L-7 — minor / would be over-engineering for the current threat model.

### Notes

- The bundle wraps `webwerkwien/contao-ai-core-bundle`. Both bundles have **different trust models**: the core bundle is also consumed by an SSH-operator CLI and intentionally stays unhardened; the backend bundle is the editor-facing hardened layer.
- All security fixes apart from C-2 (`tl_undo` snapshot) and M-2 (`--operator` option) live in the backend bundle.
- Phase-1-discovered platform pitfalls (route registration via `RoutingPluginInterface`, `BE_MOD['callback']` pattern, `X-Requested-With` header, `symfony/ai-agent` not pulled by `symfony/ai-bundle`, …) are documented in the project's vault notes (`Bundle-Entwicklung – Fallstricke`, `symfony-ai-bundle`).
