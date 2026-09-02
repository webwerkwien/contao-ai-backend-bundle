<?php declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;

// Apply to ALL six tl_user palettes — Contao switches palettes based on the
// `inherit` selector (group/extend/custom) and the `admin` flag, so missing any
// palette would hide the AI fields for users with that specific configuration.
// 🔴 `ai_base_url` steht bewusst NICHT in der `login`-Palette.
//
// Die `login`-Palette ist die Maske „Persönliche Daten", in der ein Benutzer
// sein **eigenes** Profil bearbeitet — auch ohne Admin-Rechte. Ein dort
// eintragbarer Endpunkt heißt: jeder Backend-Redakteur bestimmt, **wohin der
// Server HTTP-Anfragen stellt** (`http://169.254.169.254/`, `http://localhost:6379`).
// Klassisches SSRF, gefunden bei der Audit-Runde am 2026-09-02.
//
// ⚠️ Contaos Feldrechte (`exclude` + `alexf`, „Erlaubte Felder") lösen das NICHT.
// Gemessen in `vendor/contao/core-bundle/contao/dca/tl_user.php`, Callback
// `handleUserProfile()`: bei `do=login` setzt Contao für **jedes** Feld der
// Palette `exclude = false` — und das zu Recht, sonst bräuchte man einen Admin,
// um den eigenen Namen zu ändern. Die Rechteverwaltung greift also nur dort, wo
// ein Admin fremde Benutzer bearbeitet.
//
// 🎯 Die Palette ist damit der einzige Hebel, und sachlich der richtige: eine
// Endpunkt-Adresse ist eine Infrastruktur-Entscheidung (welcher Ollama-Server
// steht im Haus), keine persönliche Vorliebe. Der API-Schlüssel dagegen ist
// persönlich und bleibt.
//
// Zu allen sechs Paletten, weil Contao anhand des `inherit`-Selektors
// (group/extend/custom) und des `admin`-Flags umschaltet — eine ausgelassene
// Palette würde die Felder für Benutzer mit genau dieser Konfiguration
// verschwinden lassen.
$aiFields = static function (PaletteManipulator $pm, bool $withBaseUrl): PaletteManipulator {
    $pm = $pm
        ->addLegend('ai_legend', 'account_legend', PaletteManipulator::POSITION_BEFORE, false)
        ->addField('ai_platform', 'ai_legend', PaletteManipulator::POSITION_APPEND)
        ->addField('ai_api_key', 'ai_legend', PaletteManipulator::POSITION_APPEND);

    if ($withBaseUrl) {
        $pm = $pm->addField('ai_base_url', 'ai_legend', PaletteManipulator::POSITION_APPEND);
    }

    return $pm
        ->addField('ai_model', 'ai_legend', PaletteManipulator::POSITION_APPEND)
        ->addField('ai_cli_token', 'ai_legend', PaletteManipulator::POSITION_APPEND);
};

// Admin bearbeitet einen Benutzer: alle Felder.
foreach (['default', 'admin', 'group', 'extend', 'custom'] as $palette) {
    $aiFields(PaletteManipulator::create(), true)->applyToPalette($palette, 'tl_user');
}

// Benutzer bearbeitet sich selbst: ohne Endpunkt-Adresse.
$aiFields(PaletteManipulator::create(), false)->applyToPalette('login', 'tl_user');

// Until 2026-09-02 this read `'options' => ['anthropic', 'openai']` — a literal
// pair, while symfony/ai shipped 37 bridges. The two-provider limit was ours,
// not the library's. The list is now derived from the installed
// `symfony/ai-*-platform` packages via each bridge's own factory signature, so
// `composer require symfony/ai-mistral-platform` is the whole installation
// procedure for a new provider.
$GLOBALS['TL_DCA']['tl_user']['fields']['ai_platform'] = [
    'inputType'       => 'select',
    'options_callback' => ['Webwerkwien\\ContaoAiBackendBundle\\Dca\\TlUserCallback', 'platformOptions'],
    'eval'            => [
        'tl_class'           => 'w50',
        'includeBlankOption' => true,
        'chosen'             => true,
    ],
    'sql'             => "varchar(32) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_user']['fields']['ai_api_key'] = [
    'inputType' => 'text',
    'eval'      => [
        'tl_class'  => 'w50',
        'maxlength' => 255,
        'rgxp'      => 'extnd',
        // 🔴 Hier stand `'encrypt' => true`. Das Flag tut in Contao 5 NICHTS:
        // `Contao\Encryption` ist mit 5.0 entfallen, und in der Quelle des
        // core-bundle steht keine einzige Fundstelle für `'encrypt'` mehr
        // (Gegenprobe am 2026-09-02: `'mandatory'` 48 Dateien, `'maxlength'` 38,
        // `'encrypt'` 0). Der Schlüssel lag also die ganze Zeit im Klartext in
        // der Spalte — während README und Sicherheitsabschnitt das Gegenteil
        // behaupteten.
        //
        // 🎯 Entfernt statt belassen: ein totes Flag, das Sicherheit suggeriert,
        // ist schlimmer als keines. Wer hier künftig verschlüsseln will, braucht
        // save_callback/load_callback — das Flag kommt nicht zurück.
    ],
    'sql'       => "varchar(512) NOT NULL default ''",
];

// Endpunkt-Adresse. Nur nötig, wenn der Anbieter keinen festen hat — bei
// selbst gehosteten Modellen (Ollama, LM Studio) ist es genau umgekehrt: dort
// gibt es einen Host und keinen Schlüssel. Leer lassen heißt „Vorgabe der
// Bridge verwenden", die aus deren Factory-Signatur gelesen wird.
$GLOBALS['TL_DCA']['tl_user']['fields']['ai_base_url'] = [
    'inputType' => 'text',
    'eval'      => [
        'tl_class'  => 'w50',
        'maxlength' => 255,
        'rgxp'      => 'url',
        'decodeEntities' => true,
    ],
    'sql'       => "varchar(255) NOT NULL default ''",
];

// Modell. Für Anbieter mit hinterlegter Vorgabe (anthropic, openai) optional;
// für alle abgeleiteten Anbieter erforderlich, weil die Factory-Signatur den
// Endpunkt kennt, aber nicht, welches Modell eine Seite bezahlen will.
$GLOBALS['TL_DCA']['tl_user']['fields']['ai_model'] = [
    'inputType' => 'text',
    'eval'      => [
        'tl_class'  => 'w50',
        'maxlength' => 128,
    ],
    'sql'       => "varchar(128) NOT NULL default ''",
];

// Phase 10.2: ai_cli_token — readonly text input zeigt nur "vorhanden/leer"
// (über input_field_callback gerendert); echte Aktion läuft über den Wizard,
// der den AiCliTokenController via POST anspricht. Klartext-Token wird einmalig
// nach erfolgreichem Rotate via Flash-Bag in BE.info angezeigt.
$GLOBALS['TL_DCA']['tl_user']['fields']['ai_cli_token'] = [
    'input_field_callback' => ['Webwerkwien\\ContaoAiBackendBundle\\Dca\\TlUserCallback', 'tokenWidget'],
    'eval'                 => ['tl_class' => 'w50 clr', 'doNotShow' => false, 'doNotCopy' => true],
    'sql'                  => "varchar(255) NOT NULL default ''",
];
