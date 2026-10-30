<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_user']['ai_legend']  = 'KI-Agent';
$GLOBALS['TL_LANG']['tl_user']['ai_platform'] = ['Plattform', 'Welcher KI-Anbieter angesprochen werden soll.'];
$GLOBALS['TL_LANG']['tl_user']['ai_api_key']  = ['API-Key', 'Persönlicher API-Key (oder Firmen-Key). Ein gespeicherter Key wird nicht angezeigt, nur ********: so lassen = Key behalten, neuen eintragen = ersetzen, Feld leeren = KI-Modul deaktiviert.'];
$GLOBALS['TL_LANG']['tl_user']['ai_base_url'] = ['Endpunkt-Adresse', 'Nur für selbst betriebene Modelle (Ollama, LM Studio, OpenAI-kompatible Dienste). Anbieter mit API-Schlüssel nutzen ihren eigenen Endpunkt; für sie das Feld leer lassen.'];
$GLOBALS['TL_LANG']['tl_user']['ai_model']    = ['Modell', 'Modell-Kennung, z. B. „mistral-large-latest". Bei Anthropic und OpenAI optional — dort ist eine Vorgabe hinterlegt.'];
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token'] = [
    'CLI-Bridge-Token',
    'Optional: nur für „bridge clone" und „bridge rewrite" von contao-ai-cli aus dem Terminal — der Chat braucht ihn nicht. Nach Klick auf „Generieren" wird der Klartext einmalig hier angezeigt, mit dem nächsten Schritt im Terminal; danach ist nur der Hash in der DB.',
];
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_status_set']   = 'Token gesetzt (Hash gespeichert)';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_status_empty'] = 'Kein Token gesetzt';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_rotate']       = 'Generieren / Rotieren';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_clear']        = 'Löschen';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_copy']         = 'Token kopieren';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_copied']       = 'Kopiert!';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_oneshot_warning'] = 'Klartext-Token (NUR JETZT sichtbar — bitte kopieren):';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_next_step']    = 'Optional, nur für „bridge clone" und „bridge rewrite" aus dem Terminal — der Chat braucht den Token nicht. Danach im Terminal ausführen; die CLI fragt verdeckt nach dem Token:';
$GLOBALS['TL_LANG']['tl_user']['ai_cli_token_confirm']      = 'Token wirklich löschen? Der CLI-Agent kann sich danach nicht mehr authentifizieren.';

// ai_platform_ref entfiel am 2026-09-02: die Auswahlliste samt Beschriftungen
// kommt jetzt aus PlatformRegistry, abgeleitet aus den installierten Bridges.
// Eine zweite Namensliste hier wäre genau die Dublette, die still veraltet —
// sie hätte jeden neu installierten Anbieter unbeschriftet gelassen.
