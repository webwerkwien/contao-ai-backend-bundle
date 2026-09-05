<?php

declare(strict_types=1);

/*
 * Visible strings of the chat interface.
 *
 * Until 2026-09-05 these were hardcoded in the Twig template and the
 * controller — the input field, the submit button and the messages an editor
 * sees most often. On an English installation they showed German, while the
 * module name and the field labels next to them were translated correctly.
 *
 * `input_label` is not cosmetic: it is the `aria-label` of the input field,
 * i.e. what a screen reader announces.
 *
 * Every key here needs its counterpart in ../de/ai_chat.php —
 * BilingualLabelsTest enforces that.
 */

$GLOBALS['TL_LANG']['ai_chat']['input_placeholder'] = 'Ask the agent…';
$GLOBALS['TL_LANG']['ai_chat']['input_label']       = 'Message to the agent';
$GLOBALS['TL_LANG']['ai_chat']['send']              = 'Send';
$GLOBALS['TL_LANG']['ai_chat']['tools_available']   = '%s tools available';

$GLOBALS['TL_LANG']['ai_chat']['report_summary'] = 'Error report to pass on';
$GLOBALS['TL_LANG']['ai_chat']['copy']           = 'Copy';
$GLOBALS['TL_LANG']['ai_chat']['copied']         = 'Copied';

$GLOBALS['TL_LANG']['ai_chat']['unknown_error']    = 'Unknown error';
$GLOBALS['TL_LANG']['ai_chat']['rate_limited']     = 'Too many requests. Please wait a moment.';
$GLOBALS['TL_LANG']['ai_chat']['empty_message']    = 'Empty message.';
$GLOBALS['TL_LANG']['ai_chat']['message_too_long'] = 'Message too long.';
$GLOBALS['TL_LANG']['ai_chat']['internal_error']   = 'Internal error — see the log file';

// Back-end messages after creating or clearing the CLI bridge token.
$GLOBALS['TL_LANG']['ai_chat']['token_created'] = 'New CLI bridge token generated — the cleartext is shown once in the profile block below.';
$GLOBALS['TL_LANG']['ai_chat']['token_cleared'] = 'Bridge token cleared — the CLI agent can no longer authenticate.';
