# Contao AI Backend Bundle

In-browser AI agent for the Contao 5 backend. Editors and admins chat with a Claude (or GPT) agent that can read and modify Contao content through a curated set of tools — no SSH, no CLI.

> **Status: Beta (v0.1.0).** API may shift; `symfony/ai-bundle` is itself pre-1.0.

## How it fits

```
contao-ai-core-bundle  ← console commands (CRUD)
        │
        ├── contao-ai-cli            ← Python CLI (developers)
        └── contao-ai-backend-bundle ← Browser chat module (editors)  ← you are here
```

This bundle is the **browser client** for the agent. Authentication, session, CSRF and permissions ride on top of the existing Contao backend. Each backend user brings their own API key.

## Requirements

- PHP 8.2+
- Contao 5.3+
- [`webwerkwien/contao-ai-core-bundle`](https://github.com/webwerkwien/contao-ai-core-bundle) `^0.2`
- `symfony/ai-bundle` `^0.7`

## Installation

```bash
composer require webwerkwien/contao-ai-backend-bundle
vendor/bin/contao-console contao:migrate           # adds ai_api_key + ai_platform to tl_user
vendor/bin/contao-console assets:install            # publishes the Stimulus controller + CSS
```

The Contao Manager auto-discovers the bundle via the `contao-manager-plugin` entry.

## Per-user setup

In **System → Users → (user)** two new fields appear in the *AI agent* legend:

| Field | Required | Notes |
|---|---|---|
| Platform | yes | `anthropic` or `openai` |
| API key | yes | Stored encrypted (Contao DCA `encrypt` flag). Empty key = module disabled for that user. |

Grant the **`AI Chat`** module under "Allowed modules" to enable the entry.

## Available tools

| Group | Tool names |
|---|---|
| News    | `news_create`, `news_update`, `news_delete`, `news_read` |
| Page    | `page_create`, `page_update`, `page_delete`, `page_read`, `page_publish` |
| Article | `article_create`, `article_update`, `article_delete`, `article_read` |
| Content | `content_create`, `content_update`, `content_delete`, `content_read` |
| Meta    | `dca_schema`, `listing_config`, `search_query`, `record_list` |
| Macros  | `record_clone` (cascade), `record_rewrite` (server-side LLM loop) |

Permissions inherit from Contao's existing module rights. Admins see everything. Non-admins only see tools whose backing module they are allowed to use, and **delete** sub-tools are admin-only regardless of module membership. Per-record checks (page hierarchy, news-archive access, article parent-page, FAQ category access) run via Symfony voters (`ContaoCorePermissions::USER_CAN_*`) before each call.

## CLI bridge — terminal access for admins / agents

Editors use the chat module above. Developers and admins live in the terminal — and switching to a browser for bulk LLM jobs ("translate all news in archive 5", "clone this page tree with all children") is a workflow break.

The bundle exposes a HTTPS endpoint at `POST /_ai_cli/macro` that the [contao-ai-cli](https://github.com/webwerkwien/contao-ai-cli) Python client (`contao-ai-cli bridge ...`) calls with a Bearer token. The macro tools (`record_clone`, `record_rewrite`) execute server-side with the full voter pipeline + atomic `tl_version` audit — same code path as the chat module, just a different transport.

### Token setup

Per backend user, in **System → Users → (user) → AI agent**:

1. Click **Generate / Rotate** in the *CLI bridge token* section.
2. Cleartext token is shown once in the same panel with a **Copy token** button.
3. Paste it into the CLI: `contao-ai-cli bridge configure --url https://your-site.example.com --token 5.abc... --test`.

Only the `password_hash` is stored in `tl_user.ai_cli_token`. Click **Delete** to revoke.

### Why `/_ai_cli/macro` and not `/contao/...`?

The `contao_backend` firewall would 302-redirect any unauthenticated request to `/contao/login` before our Bearer auth runs. Routing the bridge outside `/contao/*` lets it fall through to the frontend (anonymous) firewall, where the controller does its own auth.

## Security model

The bundle was hardened in a four-sprint security pass against findings from two independent reviewers (Opus + Codex). Full breakdown in [CHANGELOG.md](CHANGELOG.md).

### Authentication and authorization

- **Auth** rides on the existing Contao backend session (passkey, password, 2FA — whatever the install uses).
- **Module gate**: Symfony voter `AI_CHAT_USE` requires `ai_chat` in `BackendUser->modules`.
- **Tool gate**: `ToolAccessChecker` validates the underlying module per tool. Delete tools require `BackendUser::isAdmin === true`.
- **Per-record gate**: every tool that touches a content row asserts `BackendUser::isAllowed()` / `hasAccess()` against the record's parent (news archive, page hierarchy, article parent-page) before delegating to the core command.

### Field allow-lists

Each `*_update` tool defines a strict `allowedFields()` allow-list. The agent cannot set protected DCA columns (`pid`, `tstamp`, `chmod`, `cuser`, …) even if it asks. Values containing control chars or NULs are rejected.

### Prompt-injection mitigation

- Tool outputs are wrapped in `<tool_output_data tool="…">…</tool_output_data>` sentinels. The system prompt instructs the model to treat anything inside as untrusted data.
- Free-text fields larger than 500 bytes are truncated with `…[truncated]`.
- Chat history lives **server-side** in the Symfony session keyed by user ID. Client-supplied history is ignored — fabricated `assistant` turns cannot be smuggled in.
- `username` / `language` template substitutions are regex-validated before flowing into the system prompt.

### Information disclosure

- API keys are stored with the Contao DCA `encrypt` flag, never logged, never returned to the browser. The `UserAiConfigDto` wraps the key behind a private property + getter; `__debugInfo()` redacts to `***<last4>` so casual `dump()` cannot leak it.
- Exception messages are masked (`sk-ant-…`, `sk-…`, `Bearer …` patterns), scrubbed of `kernel.project_dir`, truncated to 200 chars, and logged as `LogLevel::error` for diagnostics.
- `dca_schema` is restricted to a table allow-list and strips canonical credential/session column names.

### Audit trail

Backend invocations stamp `tl_version.username` and the audit log with the actual Contao username via the `--operator` option on the underlying core commands. CLI invocations still attribute to `$_SERVER['USER']`.

### Transport hardening

- `Cache-Control: no-store, private, max-age=0` + `Vary: Cookie` + `X-Robots-Tag: noindex, nofollow`. `charset=utf-8` is explicit.
- CSRF: every POST requires the Contao backend CSRF token.
- Same-origin verified via `Sec-Fetch-Site` and `Origin` request headers.
- Rate limit: 30 requests/minute and 500/day per user — sliding window backed by the session.

## Streaming

Responses arrive as `text/event-stream` (SSE-style frames over `fetch`-`ReadableStream`, since `EventSource` cannot POST). Events:

- `start` — model id
- `message` — content chunk (currently emitted once per response; chunked streaming will be added when the underlying platform bridge supports it)
- `done` — successful completion
- `error` — `kind: access_denied | tool_failed | agent_failed`

## Development

```bash
composer install
vendor/bin/phpstan analyse src tests --level=6
vendor/bin/phpunit
```

## License

[MIT](LICENSE)
