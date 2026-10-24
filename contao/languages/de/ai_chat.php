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
$GLOBALS['TL_LANG']['ai_chat']['attach']            = 'Datei anhängen';
$GLOBALS['TL_LANG']['ai_chat']['attach_remove']     = '%s entfernen';
$GLOBALS['TL_LANG']['ai_chat']['attachment_line']   = 'Anhang: %s';
$GLOBALS['TL_LANG']['ai_chat']['tools_available']   = '%s Werkzeuge verfügbar';

$GLOBALS['TL_LANG']['ai_chat']['report_summary'] = 'Fehlerbericht zum Weitergeben';
$GLOBALS['TL_LANG']['ai_chat']['copy']           = 'Kopieren';
$GLOBALS['TL_LANG']['ai_chat']['copied']         = 'Kopiert';

$GLOBALS['TL_LANG']['ai_chat']['unknown_error']    = 'Unbekannter Fehler';
$GLOBALS['TL_LANG']['ai_chat']['rate_limited']     = 'Zu viele Anfragen. Bitte einen Moment warten.';
$GLOBALS['TL_LANG']['ai_chat']['model_not_allowed'] = 'Dieses Modell steht hier nicht zur Auswahl.';
$GLOBALS['TL_LANG']['ai_chat']['model_label']      = 'Modell';
$GLOBALS['TL_LANG']['ai_chat']['empty_message']    = 'Leere Nachricht.';
$GLOBALS['TL_LANG']['ai_chat']['message_too_long'] = 'Nachricht zu lang.';
$GLOBALS['TL_LANG']['ai_chat']['internal_error']   = 'Interner Fehler — siehe Logfile';
$GLOBALS['TL_LANG']['ai_chat']['tool_not_allowed'] = 'Dieses Werkzeug ist für Ihr Konto nicht freigegeben.';
$GLOBALS['TL_LANG']['ai_chat']['output_cut']       = 'Die Antwort des Modells wurde an seiner Längengrenze abgeschnitten; dieser Schritt wurde nicht ausgeführt. Bitte die Anfrage in kleinere Schritte teilen.';

// Anhänge (v1.0.0): Ablehnungen am Server, als `attachment_refused` ohne Fehlerbericht
$GLOBALS['TL_LANG']['ai_chat']['attachments_too_large']        = 'Die Anhänge sind zusammen zu groß für eine Nachricht. Bitte weniger oder kleinere Dateien senden.';
$GLOBALS['TL_LANG']['ai_chat']['attachments_too_many']         = 'Höchstens %s Dateien pro Nachricht.';
$GLOBALS['TL_LANG']['ai_chat']['attachment_invalid']           = 'Die Datei „%s“ ist nicht vollständig angekommen.';
$GLOBALS['TL_LANG']['ai_chat']['attachment_duplicate']         = 'Die Datei „%s“ ist zweimal angehängt.';
$GLOBALS['TL_LANG']['ai_chat']['attachment_type_not_allowed']  = 'Dateien wie „%s“ sind in den Systemeinstellungen nicht zum Hochladen freigegeben.';
$GLOBALS['TL_LANG']['ai_chat']['attachment_unreadable']        = 'Diese Datei kann der Chat nicht lesen: „%s“.';
$GLOBALS['TL_LANG']['ai_chat']['attachment_too_large']         = '„%s“ ist größer als die erlaubten %s.';
$GLOBALS['TL_LANG']['ai_chat']['attachment_too_long']          = '„%s“ ist zu lang für den Chat: %s Zeichen, höchstens %s. Bitte kürzen oder aufteilen.';
$GLOBALS['TL_LANG']['ai_chat']['attachment_mismatch']          = 'Der Inhalt von „%s“ passt nicht zur Dateiendung.';
$GLOBALS['TL_LANG']['ai_chat']['attachment_model_cannot_read'] = 'Das Modell %2$s kann „%1$s“ nicht lesen. Bitte ein anderes Modell wählen.';

// Backend-Meldungen nach dem Erzeugen bzw. Löschen des CLI-Bridge-Tokens.
$GLOBALS['TL_LANG']['ai_chat']['token_created'] = 'Neuer CLI-Bridge-Token generiert — der Klartext ist unten im Profil-Block einmalig sichtbar.';
$GLOBALS['TL_LANG']['ai_chat']['token_cleared'] = 'Bridge-Token gelöscht — der CLI-Agent kann sich nicht mehr authentifizieren.';
