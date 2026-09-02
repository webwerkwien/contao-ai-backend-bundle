<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_user']['ai_legend']  = 'KI-Agent';
$GLOBALS['TL_LANG']['tl_user']['ai_platform'] = ['Plattform', 'Welcher KI-Anbieter angesprochen werden soll.'];
$GLOBALS['TL_LANG']['tl_user']['ai_api_key']  = ['API-Key', 'Persönlicher API-Key (oder Firmen-Key). Leer = KI-Modul deaktiviert.'];
$GLOBALS['TL_LANG']['tl_user']['ai_base_url'] = ['Endpunkt-Adresse', 'Nur nötig, wenn der Anbieter keinen festen Endpunkt hat — etwa bei selbst gehosteten Modellen (Ollama, LM Studio). Leer lassen heißt: Vorgabe des Anbieters verwenden.'];
$GLOBALS['TL_LANG']['tl_user']['ai_model']    = ['Modell', 'Modell-Kennung, z. B. „mistral-large-latest". Bei Anthropic und OpenAI optional — dort ist eine Vorgabe hinterlegt.'];
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

// ai_platform_ref entfiel am 2026-09-02: die Auswahlliste samt Beschriftungen
// kommt jetzt aus PlatformRegistry, abgeleitet aus den installierten Bridges.
// Eine zweite Namensliste hier wäre genau die Dublette, die still veraltet —
// sie hätte jeden neu installierten Anbieter unbeschriftet gelassen.
