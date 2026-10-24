<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Dca;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;

/**
 * The user form never sends a stored API key back to the browser (v0.17.0).
 *
 * Until then `ai_api_key` was a plain text input: anyone allowed to edit users in the
 * back end received every other user's key in clear in the form — checked on the test
 * server on 2026-10-14, a 108-character `sk-…` value in the HTML. The key itself stays
 * in plain text in the database (Contao 5 has no field encryption, see README); this
 * keeps it out of the form. `hideInput`/`doNotShow` keep it out of the show view, the
 * version diff and the undo view, `doNotCopy` out of a duplicated user (tl_user DCA).
 *
 * The form shows MASK when a key is stored. Sent back unchanged, the stored key stays;
 * a new value replaces it; an empty field removes it, which disables the AI module for
 * the user as before.
 *
 * ## Why the stored key is read from the database, and what that costs
 *
 * Not from `getCurrentRecord()`: in a write through contao-ai-core-bundle that record
 * already carries the values being saved, so `--set ai_api_key=********` would have
 * stored the mask. And the unchanged mask is not answered with the
 * mask either, although that would spare the write: under "edit multiple → override"
 * Contao compares against an empty value and would store the mask as the key.
 *
 * The cost: saving the form rewrites the unchanged key, so Contao records a new version
 * of the user each time — with the key in it, like every version of `tl_user`.
 */
class ApiKeyField
{
    public const MASK = '********';

    public function __construct(private readonly Connection $connection)
    {
    }

    #[AsCallback(table: 'tl_user', target: 'fields.ai_api_key.load')]
    public function onLoad(mixed $value): string
    {
        return '' === trim((string) $value) ? '' : self::MASK;
    }

    #[AsCallback(table: 'tl_user', target: 'fields.ai_api_key.save')]
    public function onSave(mixed $value, DataContainer $dc): string
    {
        $value = trim((string) $value);

        if (self::MASK !== $value) {
            return $value;
        }

        $stored = $this->connection->fetchOne('SELECT ai_api_key FROM tl_user WHERE id = ?', [(int) $dc->id]);

        return false === $stored ? '' : (string) $stored;
    }
}
