<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Dca;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;

/**
 * The user form never sends a stored API key back to the browser (v0.17.0).
 *
 * Until then `ai_api_key` was a plain text input: anyone allowed to edit users in the
 * back end received every other user's key in clear in the form — checked on the test
 * server on 2026-10-14, a 108-character `sk-…` value in the HTML. The key itself stays
 * in plain text in the database (Contao 5 has no field encryption, see README); this
 * only keeps it out of the form, the show view and the version diff (`hideInput`).
 *
 * The form shows MASK when a key is stored. Sent back unchanged, the stored key stays;
 * a new value replaces it; an empty field removes it, which disables the AI module for
 * the user as before. The same holds for a write through contao-ai-core-bundle, which
 * runs these callbacks too.
 */
class ApiKeyField
{
    public const MASK = '********';

    #[AsCallback(table: 'tl_user', target: 'fields.ai_api_key.load')]
    public function onLoad(mixed $value): string
    {
        return '' === trim((string) $value) ? '' : self::MASK;
    }

    #[AsCallback(table: 'tl_user', target: 'fields.ai_api_key.save')]
    public function onSave(mixed $value, DataContainer $dc): string
    {
        $value = trim((string) $value);

        if (self::MASK === $value) {
            return (string) ($dc->getCurrentRecord()['ai_api_key'] ?? '');
        }

        return $value;
    }
}
