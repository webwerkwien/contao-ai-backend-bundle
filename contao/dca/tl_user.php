<?php declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;

// Apply to ALL six tl_user palettes — Contao switches palettes based on the
// `inherit` selector (group/extend/custom) and the `admin` flag, so missing any
// palette would hide the AI fields for users with that specific configuration.
$pm = PaletteManipulator::create()
    ->addLegend('ai_legend', 'account_legend', PaletteManipulator::POSITION_BEFORE, false)
    ->addField('ai_platform', 'ai_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('ai_api_key', 'ai_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('ai_cli_token', 'ai_legend', PaletteManipulator::POSITION_APPEND);

foreach (['default', 'admin', 'login', 'group', 'extend', 'custom'] as $palette) {
    $pm->applyToPalette($palette, 'tl_user');
}

$GLOBALS['TL_DCA']['tl_user']['fields']['ai_platform'] = [
    'inputType' => 'select',
    'options'   => ['anthropic', 'openai'],
    'reference' => &$GLOBALS['TL_LANG']['tl_user']['ai_platform_ref'],
    'eval'      => [
        'tl_class'           => 'w50',
        'includeBlankOption' => true,
        'chosen'             => true,
    ],
    'sql'       => "varchar(32) NOT NULL default ''",
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

// Phase 10.2: ai_cli_token — readonly text input zeigt nur "vorhanden/leer"
// (über input_field_callback gerendert); echte Aktion läuft über den Wizard,
// der den AiCliTokenController via POST anspricht. Klartext-Token wird einmalig
// nach erfolgreichem Rotate via Flash-Bag in BE.info angezeigt.
$GLOBALS['TL_DCA']['tl_user']['fields']['ai_cli_token'] = [
    'input_field_callback' => ['Webwerkwien\\ContaoAiBackendBundle\\Dca\\TlUserCallback', 'tokenWidget'],
    'eval'                 => ['tl_class' => 'w50 clr', 'doNotShow' => false, 'doNotCopy' => true],
    'sql'                  => "varchar(255) NOT NULL default ''",
];
