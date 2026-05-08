<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_user']['ai_legend']  = 'AI agent';
$GLOBALS['TL_LANG']['tl_user']['ai_platform'] = ['Platform', 'Which AI provider should be used.'];
$GLOBALS['TL_LANG']['tl_user']['ai_api_key']  = ['API key', 'Personal API key (or company key). Empty = AI module disabled.'];
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

$GLOBALS['TL_LANG']['tl_user']['ai_platform_ref'] = [
    'anthropic' => 'Anthropic (Claude)',
    'openai'    => 'OpenAI (GPT)',
];
