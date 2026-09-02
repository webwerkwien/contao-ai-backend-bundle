<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_user']['ai_legend']  = 'AI agent';
$GLOBALS['TL_LANG']['tl_user']['ai_platform'] = ['Platform', 'Which AI provider should be used.'];
$GLOBALS['TL_LANG']['tl_user']['ai_api_key']  = ['API key', 'Personal API key (or company key). Empty = AI module disabled.'];
$GLOBALS['TL_LANG']['tl_user']['ai_base_url'] = ['Endpoint URL', 'Only needed when the provider has no fixed endpoint - typically self-hosted models (Ollama, LM Studio). Leave empty to use the provider default.'];
$GLOBALS['TL_LANG']['tl_user']['ai_model']    = ['Model', 'Model identifier, e.g. "mistral-large-latest". Optional for Anthropic and OpenAI, which ship a default.'];
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token'] = [
    'CLI bridge token',
    'Bearer token for the Python CLI agent. After clicking "Generate" the cleartext is shown once in the info box on top — copy it, only the hash is stored in the DB.',
];
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_status_set']   = 'Token set (hash stored)';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_status_empty'] = 'No token set';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_rotate']       = 'Generate / Rotate';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_clear']        = 'Delete';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_copy']         = 'Copy token';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_copied']       = 'Copied!';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_oneshot_warning'] = 'Cleartext token (visible ONLY NOW — please copy):';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_confirm']      = 'Really delete the token? The CLI agent will no longer be able to authenticate.';

// ai_platform_ref was dropped on 2026-09-02: the option list and its labels
// now come from PlatformRegistry, derived from the installed bridges. A second
// name list here would be the duplicate that goes stale — it would have left
// every newly installed provider unlabelled.
