<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service;

/**
 * Removes credentials from anything that leaves the server.
 *
 * ## Why this replaced two pattern lists
 *
 * Until 2026-09-02 the masking lived twice — `AiStreamController::maskSecrets()`
 * and an inline copy in `CliBridgeController` — and both knew exactly three
 * shapes: `sk-ant-…`, `sk-` plus alphanumerics, and `Bearer …`. Measured against
 * seven realistic key formats, four went through unmasked, including **OpenAI's
 * current `sk-proj-…`**: the old `\bsk-[A-Za-z0-9]{20,}` breaks at the hyphen
 * after `proj`. That gap predated the provider expansion; adding four providers
 * merely widened it.
 *
 * 🎯 **A pattern list guesses what a secret looks like, and it goes stale with
 * every provider that ships a new prefix.** The decisive fix is the other way
 * round: we *hold* the user's key, so we can strike the literal value. That also
 * covers the case no pattern ever could — an opaque key with no prefix at all,
 * like Mistral's.
 *
 * The patterns stay as a net for secrets we do *not* hold: a bearer token from
 * somewhere else, a key pasted into a prompt by a user.
 */
final class CredentialMasker
{
    /**
     * Deliberately loose on the tail character class so a hyphenated prefix
     * (`sk-proj-`, `sk-or-v1-`, `sk-ant-api03-`) cannot terminate the match.
     */
    private const PATTERNS = [
        '/sk-[A-Za-z0-9_-]{16,}/'          => 'sk-***',
        '/AIza[A-Za-z0-9_-]{20,}/'         => 'AIza***',
        '/Bearer\s+[A-Za-z0-9._\-]{16,}/i' => 'Bearer ***',
    ];

    /** Shorter values are too common in ordinary prose to strike blindly. */
    private const MIN_LITERAL_LENGTH = 8;

    /**
     * @param string ...$knownSecrets values held by the caller — the user's API
     *        key, a bridge token. Empty and very short values are ignored.
     */
    public static function mask(string $message, #[\SensitiveParameter] string ...$knownSecrets): string
    {
        foreach ($knownSecrets as $secret) {
            if (mb_strlen($secret) >= self::MIN_LITERAL_LENGTH) {
                $message = str_replace($secret, '***', $message);
            }
        }

        return (string) preg_replace(
            array_keys(self::PATTERNS),
            array_values(self::PATTERNS),
            $message,
        );
    }

    /**
     * Context for a logger that carries no cleartext.
     *
     * The previous call sites passed `['exception' => $e]`, so the *full*
     * exception — message included — reached the log file while only the browser
     * got a masked string. `getTraceAsString()` is used instead of the exception
     * object because `#[\SensitiveParameter]` renders protected arguments as
     * `Object(SensitiveParameterValue)` there.
     *
     * @return array<string, string|int>
     */
    public static function context(\Throwable $e, #[\SensitiveParameter] string ...$knownSecrets): array
    {
        return [
            'exception_class' => $e::class,
            'message'         => self::mask($e->getMessage(), ...$knownSecrets),
            'file'            => $e->getFile(),
            'line'            => $e->getLine(),
            'trace'           => self::mask($e->getTraceAsString(), ...$knownSecrets),
        ];
    }
}
