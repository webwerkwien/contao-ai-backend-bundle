<?php declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;

// Apply to ALL six tl_user palettes — Contao switches palettes based on the
// `inherit` selector (group/extend/custom) and the `admin` flag, so missing any
// palette would hide the AI fields for users with that specific configuration.
// 🔴 `ai_base_url` is deliberately NOT in the `login` palette.
//
// The `login` palette is the "Personal data" form in which a user edits their
// **own** profile — without admin rights too. An endpoint editable there means:
// every back-end editor decides **where the server sends HTTP requests**
// (`http://169.254.169.254/`, `http://localhost:6379`). Classic SSRF.
//
// ⚠️ Contao's field rights (`exclude` + `alexf`, "allowed fields") do NOT solve
// this. Measured in `vendor/contao/core-bundle/contao/dca/tl_user.php`, callback
// `handleUserProfile()`: with `do=login` Contao sets `exclude = false` for
// **every** field of the palette — and rightly so, otherwise changing one's own
// name would need an admin. Rights management only applies where an admin edits
// other users.
//
// 🎯 The palette is therefore the only lever, and the right one: an endpoint
// address is an infrastructure decision (which Ollama server runs in-house), not
// a personal preference. The API key, on the other hand, is personal and stays.
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

// An admin edits a user: all fields.
foreach (['default', 'admin', 'group', 'extend', 'custom'] as $palette) {
    $aiFields(PaletteManipulator::create(), true)->applyToPalette($palette, 'tl_user');
}

// A user edits their own profile: without the endpoint address.
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
        // 🔴 This used to say `'encrypt' => true`. The flag does NOTHING in Contao 5:
        // `Contao\Encryption` was dropped in 5.0, and the core-bundle's source has
        // not a single occurrence of `'encrypt'` left (counter-check on 2026-09-02:
        // `'mandatory'` 48 files, `'maxlength'` 38, `'encrypt'` 0). So the key sat
        // in the column in clear text all along — while the README and its security
        // section claimed the opposite.
        //
        // 🎯 Removed rather than kept: a dead flag that suggests security is worse
        // than none. Whoever wants to encrypt here later needs
        // save_callback/load_callback — the flag does not come back.
        //
        // v0.17.0, see ApiKeyField: hideInput makes a password field and leaves the
        // field out of the detail view and the version comparison; doNotShow also
        // out of the undo view (tl_undo checks doNotShow only); doNotCopy keeps the
        // key out of a duplicated user, as with ai_cli_token. ApiKeyField keeps the
        // stored value out of the form — hideInput alone still sent it in the value
        // attribute.
        'hideInput' => true,
        'doNotShow' => true,
        'doNotCopy' => true,
    ],
    'sql'       => "varchar(512) NOT NULL default ''",
];

// Endpoint address. Only for self-hosted models (Ollama, LM Studio, OpenAI-compatible
// services): there is a host and no key. A provider that requires a key keeps its own
// endpoint; an address there is refused (v1.1.0, PlatformRegistry::createPlatform()).
// Left empty means "use the bridge's default", which is read from its factory signature.
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

// Model. Optional for providers with a stored default (anthropic, openai);
// required for all derived providers, because the factory signature knows the
// endpoint but not which model a site wants to pay for.
$GLOBALS['TL_DCA']['tl_user']['fields']['ai_model'] = [
    'inputType' => 'text',
    // v0.20.0: an empty field shows the model that runs then; the chat offers this one
    // and the installation's others (contao_ai_backend.models) to pick from.
    'load_callback' => [['Webwerkwien\\ContaoAiBackendBundle\\Dca\\TlUserCallback', 'modelPlaceholder']],
    'eval'      => [
        'tl_class'  => 'w50',
        'maxlength' => 128,
    ],
    'sql'       => "varchar(128) NOT NULL default ''",
];

// ai_cli_token — a read-only text input shows only "set/empty" (rendered via
// input_field_callback); the real action runs through the wizard, which calls
// AiCliTokenController via POST. The clear-text token is shown once, after a
// successful rotate, via the flash bag in BE.info.
$GLOBALS['TL_DCA']['tl_user']['fields']['ai_cli_token'] = [
    'input_field_callback' => ['Webwerkwien\\ContaoAiBackendBundle\\Dca\\TlUserCallback', 'tokenWidget'],
    'eval'                 => ['tl_class' => 'w50 clr', 'doNotShow' => false, 'doNotCopy' => true],
    'sql'                  => "varchar(255) NOT NULL default ''",
];
