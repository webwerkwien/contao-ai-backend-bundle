<?php

declare(strict_types=1);

/*
 * Sichtbare Texte der Chat-Oberfläche.
 *
 * Bis 2026-09-05 standen sie hartcodiert im Twig-Template und im Controller —
 * ausgerechnet Eingabefeld, Absende-Knopf und die Meldungen, die ein Redakteur
 * am häufigsten sieht. Auf einer englischen Installation stand dort Deutsch,
 * während Modulname und Feldbeschriftungen daneben korrekt übersetzt waren.
 *
 * `input_label` ist kein Schönheitsfehler: Das ist das `aria-label` des
 * Eingabefelds, also der Text, den ein Screenreader vorliest.
 *
 * Jeder Schlüssel hier braucht sein Gegenstück in ../en/ai_chat.php —
 * BilingualLabelsTest wacht darüber.
 */

$GLOBALS['TL_LANG']['ai_chat']['input_placeholder'] = 'Frag den Agenten…';
$GLOBALS['TL_LANG']['ai_chat']['input_label']       = 'Nachricht an den Agenten';
$GLOBALS['TL_LANG']['ai_chat']['send']              = 'Senden';
$GLOBALS['TL_LANG']['ai_chat']['tools_available']   = '%s Werkzeuge verfügbar';

$GLOBALS['TL_LANG']['ai_chat']['report_summary'] = 'Fehlerbericht zum Weitergeben';
$GLOBALS['TL_LANG']['ai_chat']['copy']           = 'Kopieren';
$GLOBALS['TL_LANG']['ai_chat']['copied']         = 'Kopiert';

$GLOBALS['TL_LANG']['ai_chat']['unknown_error']    = 'Unbekannter Fehler';
$GLOBALS['TL_LANG']['ai_chat']['rate_limited']     = 'Zu viele Anfragen. Bitte einen Moment warten.';
$GLOBALS['TL_LANG']['ai_chat']['empty_message']    = 'Leere Nachricht.';
$GLOBALS['TL_LANG']['ai_chat']['message_too_long'] = 'Nachricht zu lang.';
$GLOBALS['TL_LANG']['ai_chat']['internal_error']   = 'Interner Fehler — siehe Logfile';

// Backend-Meldungen nach dem Erzeugen bzw. Löschen des CLI-Bridge-Tokens.
$GLOBALS['TL_LANG']['ai_chat']['token_created'] = 'Neuer CLI-Bridge-Token generiert — der Klartext ist unten im Profil-Block einmalig sichtbar.';
$GLOBALS['TL_LANG']['ai_chat']['token_cleared'] = 'Bridge-Token gelöscht — der CLI-Agent kann sich nicht mehr authentifizieren.';
