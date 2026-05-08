<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_user']['ai_legend']  = 'KI-Agent';
$GLOBALS['TL_LANG']['tl_user']['ai_platform'] = ['Plattform', 'Welcher KI-Anbieter angesprochen werden soll.'];
$GLOBALS['TL_LANG']['tl_user']['ai_api_key']  = ['API-Key', 'Persönlicher API-Key (oder Firmen-Key). Leer = KI-Modul deaktiviert.'];
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token'] = [
    'CLI-Bridge-Token',
    'Bearer-Token für den Python-CLI-Agent. Nach Klick auf „Generieren" wird der Klartext einmalig oben in der Info-Box angezeigt — bitte kopieren, danach ist nur der Hash in der DB.',
];
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_status_set']   = 'Token gesetzt (Hash gespeichert)';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_status_empty'] = 'Kein Token gesetzt';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_rotate']       = 'Generieren / Rotieren';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_clear']        = 'Löschen';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_copy']         = 'Token kopieren';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_copied']       = 'Kopiert!';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_oneshot_warning'] = 'Klartext-Token (NUR JETZT sichtbar — bitte kopieren):';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_confirm']      = 'Token wirklich löschen? Der CLI-Agent kann sich danach nicht mehr authentifizieren.';

$GLOBALS['TL_LANG']['tl_user']['ai_platform_ref'] = [
    'anthropic' => 'Anthropic (Claude)',
    'openai'    => 'OpenAI (GPT)',
];
