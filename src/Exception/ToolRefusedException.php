<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Exception;

/**
 * The tool ran, answered properly, and said no.
 *
 * 🔴 Found on 2026-09-02 while live-testing the bridge with a wrong id:
 *
 *     bridge clone --table tl_faq_category --source-id 1
 *     -> HTTP 500: Tool "record_clone" fehlgeschlagen: FAQ-Kategorie 1 nicht gefunden.
 *
 * A record that does not exist is not a server error. Every failure of an
 * underlying console command used to become {@see ToolExecutionException} and
 * therefore HTTP 500, so a typo in an id was indistinguishable from a crash.
 *
 * 🎯 **The reason it became urgent is that we had just attached a diagnosis to
 * that status code.** contao-ai-cli v0.15.0 stopped treating HTTP 500 from
 * `bridge configure --test` as "auth OK" and started reporting
 * `auth_ok_server_error` — *"your token works, the bridge is broken"*. With a
 * mistyped id producing the same 500, that message would have accused a healthy
 * bridge. One status code carrying two meanings is tolerable until something
 * starts reading it.
 *
 * ## Where the line is drawn, and why it needs no string matching
 *
 * The distinction is **whether the command answered at all**, not what it said:
 *
 * | the command … | exception | HTTP |
 * |---|---|---|
 * | returned a structured `{"status":"error","message":…}` | `ToolRefusedException` | 422 |
 * | produced no JSON, an unusable shape, or threw | `ToolExecutionException` | 500 |
 *
 * A command that emits its own error object has run, understood the request and
 * declined it — the caller can act on that. One that cannot produce an answer is
 * a fault on our side.
 *
 * ⚠️ **The trade-off, stated rather than hidden:** a genuine server-side failure
 * (database gone, filesystem full) can also arrive as a structured error, and
 * that will now be reported as 422 rather than 500. The alternative was matching
 * on German error text, which fails the first time a message is reworded — a
 * silent regression instead of an occasional mislabel. The message travels
 * either way, so nothing is lost but the status number.
 *
 * The lasting fix belongs in contao-ai-core-bundle: `outputError()` already
 * carries a `code` field that all 144 call sites leave at the default. Giving it
 * meaning would let this be decided rather than inferred.
 */
class ToolRefusedException extends \RuntimeException
{
}
