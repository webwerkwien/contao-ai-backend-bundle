# contao-ai-backend-bundle

In-browser AI agent for the Contao 5.7 and 6 backend. Editors and admins chat with an AI agent (any provider `symfony/ai` supports) that can read and modify Contao content through a curated set of tools — no SSH, no CLI. Plus an HTTPS bridge endpoint that lets [contao-ai-cli](https://github.com/webwerkwien/contao-ai-cli) trigger bulk macro operations from the terminal without switching to the browser.

> **Pre-1.0.** Runs in production on the author's own installations. Interfaces — tool
> signatures, bridge JSON, DCA fields — still change between minor versions, and
> `symfony/ai` is itself pre-1.0. Read the changelog before updating.

> **You bring your own model access.** Each backend user enters their provider's credentials — an API key, or an endpoint for a self-hosted model (see *Providers*) — in their profile (System → Users → AI agent). Without a key, the chat module is disabled for that user. The bundle does not ship with a service-level key.

## ConpAI — the contao-ai family

**ConpAI** is the family name of the contao-ai packages. The story is *kanpai* (乾杯, Japanese for "cheers") — a toast to Contao and AI working together. The P stands for *protocol*: changes are recorded in Contao's own [audit trail](https://github.com/webwerkwien/contao-ai-core-bundle#audit-trail) instead of bypassing it. The package names stay `contao-ai-*`.

| Package | What it is | When to use |
|---|---|---|
| [contao-ai-core-bundle](https://github.com/webwerkwien/contao-ai-core-bundle) | Contao bundle exposing CMS operations as Symfony console commands. | Required as the foundation layer. Install on any Contao site you want to manage via AI. |
| [contao-ai-cli](https://github.com/webwerkwien/contao-ai-cli) | Python CLI — connects to Contao via SSH and runs commands. | For developers and agencies: manage Contao from the terminal or hand control to an AI agent. |
| **contao-ai-backend-bundle** *(this package)* | Contao backend module — browser-based AI chat interface for any model provider (Anthropic, OpenAI, OpenRouter, Ollama, any OpenAI-compatible service, more via `symfony/ai`). | For editors and admins: AI directly inside the Contao backend, no SSH or terminal needed. |

## What it does

contao-ai-backend-bundle is the **browser client** for AI-powered Contao content management. Authentication, session, CSRF and permissions ride on top of the existing Contao backend — each backend user brings their own API key. A second entry point exposes the macro tools (`record_clone`, `record_rewrite`) over HTTPS for the [contao-ai-cli](https://github.com/webwerkwien/contao-ai-cli) `bridge` workflow.

## Requirements

- PHP ^8.2
- Contao 5.7 or 6.x (`^5.7 || ^6.0`)

`symfony/ai` needs Symfony 7.3 or later, which rules out Contao 5.3 (Symfony 6.4). Up to v0.9.3 the requirements said `^5.3`, but the bundle could never actually be installed there. Contao 5.4 to 5.6 are not supported either; on those, Composer keeps v0.9.3.

Also installed: [`webwerkwien/contao-ai-core-bundle`](https://github.com/webwerkwien/contao-ai-core-bundle) and `symfony/ai-*`.

## Installation

```bash
composer require "webwerkwien/contao-ai-backend-bundle:>=0.1 <2.0"
vendor/bin/contao-console contao:migrate           # adds ai_platform, ai_model, ai_base_url, ai_api_key, ai_cli_token to tl_user
```

`contao-ai-cli bundle install backend` does both steps, `contao:migrate` included since CLI v1.7.0.

The Contao Manager auto-discovers the bundle via the `contao-manager-plugin` entry.

## Per-user setup

In **System → Users → (user)**, these fields appear in the *AI agent* legend:

| Field | Required | Notes |
|---|---|---|
| Platform | yes | See *Providers* below. |
| API key | depends | Stored in plain text — see *Security model*. Cloud providers need one; a self-hosted provider takes an endpoint instead. |
| Endpoint URL | depends | **Administrators only** — not editable under *Personal data*, because it decides where the server sends requests. Only for providers without a fixed endpoint: Ollama, LM Studio, or any OpenAI-compatible service. Empty means "use the provider default". |
| Model | depends | Optional for Anthropic and OpenAI, which ship a default. Required elsewhere. |
| CLI bridge token | optional | Only for `bridge clone` / `bridge rewrite` of [contao-ai-cli](https://github.com/webwerkwien/contao-ai-cli) from the terminal — the chat does not need it. Click *Generate / Rotate* to mint a token. Cleartext is shown once with a *Copy token* button and, beneath it, the command that completes the setup with this site's address (`contao-ai-cli --session <name> bridge configure --url https://… --test`, which asks for the token with hidden input); only the `password_hash` is stored in the database. *Delete* revokes. |

## Providers

Installed with the bundle:

| Provider | Credentials | Covers |
|---|---|---|
| Anthropic (Claude) | API key | 27 models |
| OpenAI (GPT) | API key | 66 models |
| OpenRouter | API key | ~538 models across many vendors, one key |
| Ollama | endpoint, no key | models running on your own machine |
| Generic (OpenAI-compatible) | endpoint, key optional | any service speaking `/v1/chat/completions` |

**To add another,** install its package — that is the whole procedure:

```bash
composer require "symfony/ai-mistral-platform:^0.14"
```

The list in the Platform select is derived from the installed
`symfony/ai-*-platform` packages, read from each bridge's own factory signature.
Nothing to register, no code to change. `composer suggests` lists further ones.

Grant the **`AI Chat`** module under "Allowed modules" to enable the chat entry. The CLI bridge does not require the module mount but still respects the same per-record permission voters.

The chat sits in the "System" group of the back-end menu, at `/contao/ai-chat`. Its template is `@Contao/backend/ai_chat.html.twig` and can be overridden in `templates/backend/ai_chat.html.twig` like any Contao template.

Since v0.14.0 answers are shown as Markdown (headings, lists, tables, code, links) and the chat follows the back end's light and dark scheme. The renderer lives in the template and builds every element as text, so HTML in an answer is never parsed; links open only for http(s) and for paths on this host. An overridden template keeps whatever renderer it was copied with — compare it after an update.

## Available tools

| Group | Tool names |
|---|---|
| News    | `news_create`, `news_update`, `news_delete`, `news_read` |
| Page    | `page_create`, `page_update`, `page_delete`, `page_read`, `page_publish` |
| Article | `article_create`, `article_update`, `article_delete`, `article_read` |
| Content | `content_create`, `content_update`, `content_delete`, `content_read` |
| Meta    | `dca_schema`, `dca_palette`, `dca_options` (v0.16.0), `listing_config`, `search_query`, `record_list` |
| Macros  | `record_clone` (cascade), `record_rewrite` (server-side LLM loop) |
| Extensions | `ext_list`, `ext_run` — console commands other extensions declare with `#[AiContract]` |
| Translation links | `language_link`, `language_check` — only with terminal42/contao-changelanguage and core-bundle ≥ 1.4.0 |

**Extension commands** are offered per user: a command appears when the user's module rights reach every table its contract names; a command that names no tables is admin-only; one that states something `irreversible` asks the user before it runs.

Permissions inherit from Contao's existing module rights. Admins see everything. Non-admins only see tools whose backing module they are allowed to use, and **delete** sub-tools are admin-only regardless of module membership. Per-record checks (page hierarchy, news-archive access, article parent-page, FAQ category access) run via Symfony voters (`ContaoCorePermissions::USER_CAN_*`) before each call. Every write also respects the user's **field rights** ("Allowed fields" in the user group), exactly as the back-end form does: a field the user may not edit there is refused in the chat too, and when creating a record only a value other than the field's default needs the right (since v0.17.0).

## CLI bridge — terminal access for admins and agents

Editors use the chat module above. Developers and admins live in the terminal — and switching to a browser for bulk LLM jobs ("translate all news in archive 5", "clone this page tree with all children") is a workflow break.

The bundle exposes a HTTPS endpoint at `POST /_ai_cli/macro` that the [contao-ai-cli](https://github.com/webwerkwien/contao-ai-cli) Python client (`contao-ai-cli bridge ...`) calls with a Bearer token. The macro tools (`record_clone`, `record_rewrite`) execute server-side with the full voter pipeline + atomic `tl_version` audit — same code path as the chat module, just a different transport.

### Why `/_ai_cli/macro` and not `/contao/...`?

The `contao_backend` firewall would 302-redirect any unauthenticated request to `/contao/login` before our Bearer auth runs. Routing the bridge outside `/contao/*` lets it fall through to the frontend (anonymous) firewall, where the controller does its own auth.

## Security model

Two things decide how you deploy this. Everything else is implementation detail
and lives in the changelog.

⚠️ **API keys are stored in plain text** in `tl_user.ai_api_key` — Contao 5 has no
field encryption. Treat read access to the database as equivalent to holding every
key in it, and grant it accordingly. That includes copies of it:

- **Every database backup holds the keys** — `contao:backup:create`, the backup Contao
  makes before each migration, any `mysqldump`.
- **So does the version history of `tl_user`.** A key you replaced stays in the older
  versions. After changing a key, remove those versions before a backup leaves the
  server, or treat the old key as known and revoke it.
- **The back end does not show the key** (since v0.17.0): the user form shows
  `********` for a stored key, and Contao leaves the field out of the show view, the
  version diff, the undo view and "edit multiple"; a duplicated user does not get it.
  Leave the mask to keep the key, enter a new one to replace it, clear the field to
  disable the AI module for the user. Saving the form records a new version of the user,
  with the key in it.

Encrypting the column with a key from the environment was considered and not done: on a
server, whoever reads the environment also reads the database credentials, so it would
only protect a backup that travels without the files.

**What a leaked key costs, and how to cap it.** A provider key opens no door to the
site; it lets someone use the model on your bill — and, depending on the provider,
reach files or assistants stored under that key's project. Use **one key per
installation**, in its own workspace or project **with a low spending limit**, so a
leaked key is capped and revoking it affects only that site. Revoking and replacing a
key in the provider's console takes a minute.

⚠️ **Content the agent reads can try to instruct it.** Tool output is wrapped,
truncated and declared as untrusted data, destructive operations need a real user
turn, and an injected instruction can never exceed the rights of the user whose
session is running — but none of that is isolation. A change made this way stays
within that user's rights and is versioned in `tl_version` under their name, so it
is visible and revertible. Be deliberate about pointing the agent at tables that
take input from outside the editorial team: form submissions, comments, imported
feeds.

Beyond that: the agent acts as the logged-in backend user and re-checks that
user's permissions per record and per field ("Allowed fields", since v0.17.0),
`*_delete` tools are admin-only, write tools accept only an explicit allow-list of
fields, and every write is attributed to the acting
user in `tl_version` and the system log.

## Streaming

Chat responses arrive as `text/event-stream` (SSE-style frames over `fetch`-`ReadableStream`, since `EventSource` cannot POST). Events:

- `start` — model id
- `message` — content chunk (currently emitted once per response; chunked streaming will be added when the underlying platform bridge supports it)
- `done` — successful completion
- `error` — `kind: access_denied | tool_refused | tool_failed | agent_failed`

The four `error` kinds split into two groups, and the split matters:

| kind | what happened | `report` field |
| --- | --- | --- |
| `access_denied` | a permission worked as designed | — |
| `tool_refused` | the command ran, understood the request and declined (e.g. "News-Eintrag 42 nicht gefunden") | — |
| `tool_failed` | a tool broke | ✓ |
| `agent_failed` | the agent broke | ✓ |

The first two are *answers* and carry no report — offering to report them would
train users to send noise and bury the two cases that are genuinely defects. The
last two carry `report`, a ready-to-forward Markdown block.

Since v0.12.0 a crash inside a core command is `tool_failed` rather than `tool_refused`
(with contao-ai-core-bundle v1.3.0 or later, which marks it). Two mistakes of the model
go back to the model, which calls again, instead of ending the chat: arguments that do
not fit a tool (a missing parameter, a value the tool's types reject) and a tool name
that does not exist.

Since v0.15.0 a refusal carries what the DCA callbacks told the editor through Contao's
messages (with contao-ai-core-bundle v1.6.0 or later): `… — Contao meldet: Fehler: …`.
A refusing callback often gives its reason only there.

**Who sees what is decided server-side, at the moment the event is emitted.** An
admin receives the full report including the (masked) exception message; anyone
else receives the summary with the message stripped, so the H-6 guarantee — the
raw exception never reaches the client — keeps holding unchanged for the audience
it was written for. The browser only expands the block; it never fetches more.

## Development

```bash
composer install
vendor/bin/phpstan analyse src tests --level=6
vendor/bin/phpunit
```

## License

MIT — see [LICENSE](LICENSE).

This software is provided "as is", without warranty of any kind. The authors accept no liability for any damages arising from its use.
