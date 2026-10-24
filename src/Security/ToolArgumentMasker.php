<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Security;

use Webwerkwien\ContaoAiCoreBundle\Service\CredentialMasker;

/**
 * Blanks secrets in the arguments of a tool call before they are logged (v0.14.1).
 *
 * CredentialMasker catches key formats (sk-…, Bearer); a password has none. So values
 * under names that read like a secret go as well — as an argument key (`api-key`, also
 * with a list of values), as `name=value` or `--name value` in a text (ext_run's `--set`
 * pairs), and inside a JSON text (ext_run takes its options as a JSON string too).
 *
 * The list of names is the one core-bundle v1.5.0 uses for the tl_log line of
 * contao:ai:run (CommandLineRedactor), kept here because this bundle runs with older
 * core versions. Over-blanking costs a detail in the log, nothing else.
 *
 * ⚠️ The model provider still receives what the model sent; this is about logs.
 */
final class ToolArgumentMasker
{
    public const MASK = '***';

    /** As CommandLineRedactor::SECRET in core-bundle v1.5.0. */
    public const SECRET_NAME = '/passw(?:or)?d|passwort|passphrase|pass(?![a-z])|secret|token|api[-_]?key|credential|private[-_]?key/i';

    /** What stands in for a text too long to be checked: withheld, not passed through. */
    public const WITHHELD = '[withheld: too long to check for secrets]';

    private const NAME  = '[\w.-]*(?:passw(?:or)?d|passwort|passphrase|pass(?![a-z])|secret|token|api[-_]?key|credential|private[-_]?key)[\w.-]*';

    // Unrolled, so a long quoted value is no deep recursion for PCRE's JIT stack.
    private const VALUE = '(?:"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"|\'[^\']*\'|\S+)';

    /**
     * @param array<mixed> $arguments
     *
     * @return array<mixed>
     */
    public static function mask(array $arguments): array
    {
        foreach ($arguments as $key => $value) {
            if (\is_string($key) && 1 === preg_match(self::SECRET_NAME, $key)) {
                $arguments[$key] = self::maskAll($value);
                continue;
            }

            if (\is_array($value)) {
                $arguments[$key] = self::mask($value);
                continue;
            }

            if (\is_string($value)) {
                $arguments[$key] = self::maskText($value);
            }
        }

        return $arguments;
    }

    public static function maskText(string $text): string
    {
        $text = CredentialMasker::mask($text);

        // Options as a JSON string: mask the structure, so `"api-key": "…"` goes too.
        $start = ltrim($text)[0] ?? '';
        if ('{' === $start || '[' === $start) {
            $decoded = json_decode($text, true);

            if (\is_array($decoded)) {
                return json_encode(self::mask($decoded), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: self::WITHHELD;
            }
        }

        // A whole value that is `name=…` — an element of ext_run's `set` list — goes
        // after the `=` entirely: a passphrase may contain spaces.
        if (1 === preg_match('/^\s*(' . self::NAME . ')\s*=/i', $text, $whole)) {
            return $whole[1] . '=' . self::MASK;
        }

        // "name": value — JSON the decoder refused (a trailing comma, single quotes),
        // which ExtTool rejects only after this entry was written.
        $text = preg_replace('/(["\']?)(' . self::NAME . ')\1\s*:\s*' . self::VALUE . '/i', '$1$2$1: ' . self::MASK, $text);

        // name=value
        $text = null === $text ? null : preg_replace('/(?<![\w.-])(' . self::NAME . ')=' . self::VALUE . '/i', '$1=' . self::MASK, $text);

        // --name value, unless the next word is an option itself
        $text = null === $text ? null : preg_replace('/(--' . self::NAME . ')(\s+)(?!-)' . self::VALUE . '/i', '$1$2' . self::MASK, $text);

        // A failed replacement (PCRE limits) would hand back the secret or nothing.
        return $text ?? self::WITHHELD;
    }

    private static function maskAll(mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_map(self::maskAll(...), $value);
        }

        return null === $value ? null : self::MASK;
    }
}
