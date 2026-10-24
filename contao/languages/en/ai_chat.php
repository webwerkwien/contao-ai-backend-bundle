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
$GLOBALS['TL_LANG']['ai_chat']['model_not_allowed'] = 'This model is not one of the choices here.';
$GLOBALS['TL_LANG']['ai_chat']['model_label']      = 'Model';
$GLOBALS['TL_LANG']['ai_chat']['empty_message']    = 'Empty message.';
$GLOBALS['TL_LANG']['ai_chat']['message_too_long'] = 'Message too long.';
$GLOBALS['TL_LANG']['ai_chat']['internal_error']   = 'Internal error — see the log file';
$GLOBALS['TL_LANG']['ai_chat']['tool_not_allowed'] = 'This tool is not enabled for your account.';
$GLOBALS['TL_LANG']['ai_chat']['output_cut']       = 'The model\'s answer was cut off at its length limit; that step was not run. Please split the request into smaller steps.';

// Attachments (v1.0.0): refusals on the server, as `attachment_refused` without a report
$GLOBALS['TL_LANG']['ai_chat']['attachments_too_large']        = 'The attachments are too large for one message. Please send fewer or smaller files.';
$GLOBALS['TL_LANG']['ai_chat']['attachments_too_many']         = 'At most %s files per message.';
$GLOBALS['TL_LANG']['ai_chat']['attachment_invalid']           = 'The file "%s" did not arrive complete.';
$GLOBALS['TL_LANG']['ai_chat']['attachment_duplicate']         = 'The file "%s" is attached twice.';
$GLOBALS['TL_LANG']['ai_chat']['attachment_type_not_allowed']  = 'Files like "%s" are not allowed for upload in the system settings.';
$GLOBALS['TL_LANG']['ai_chat']['attachment_unreadable']        = 'The chat cannot read this file: "%s".';
$GLOBALS['TL_LANG']['ai_chat']['attachment_too_large']         = '"%s" is larger than the allowed %s.';
$GLOBALS['TL_LANG']['ai_chat']['attachment_too_long']          = '"%s" is too long for the chat: %s characters, at most %s. Please shorten or split it.';
$GLOBALS['TL_LANG']['ai_chat']['attachment_mismatch']          = 'The content of "%s" does not match its file extension.';
$GLOBALS['TL_LANG']['ai_chat']['attachment_model_cannot_read'] = 'The model %2$s cannot read "%1$s". Please choose another model.';

// Back-end messages after creating or clearing the CLI bridge token.
$GLOBALS['TL_LANG']['ai_chat']['token_created'] = 'New CLI bridge token generated — the cleartext is shown once in the profile block below.';
$GLOBALS['TL_LANG']['ai_chat']['token_cleared'] = 'Bridge token cleared — the CLI agent can no longer authenticate.';
