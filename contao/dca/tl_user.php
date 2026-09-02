<?php declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;

// Apply to ALL six tl_user palettes — Contao switches palettes based on the
// `inherit` selector (group/extend/custom) and the `admin` flag, so missing any
// palette would hide the AI fields for users with that specific configuration.
$pm = PaletteManipulator::create()
    ->addLegend('ai_legend', 'account_legend', PaletteManipulator::POSITION_BEFORE, false)
    ->addField('ai_platform', 'ai_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('ai_api_key', 'ai_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('ai_base_url', 'ai_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('ai_model', 'ai_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('ai_cli_token', 'ai_legend', PaletteManipulator::POSITION_APPEND);

foreach (['default', 'admin', 'login', 'group', 'extend', 'custom'] as $palette) {
    $pm->applyToPalette($palette, 'tl_user');
}

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
        'encrypt'   => true,
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
