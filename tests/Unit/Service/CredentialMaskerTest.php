<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Service\CredentialMasker;

/**
 * The masking that used to exist twice and covered three shapes out of seven.
 *
 * The table below is the measurement from 2026-09-02 that started this: each of
 * these is a realistic key format, and four of them passed the old patterns
 * untouched — including OpenAI's `sk-proj-…`, which the bundle had supported
 * since April.
 */
class CredentialMaskerTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function keyFormats(): iterable
    {
        yield 'Anthropic'        => ['sk-ant-api03-AbCdEf1234567890XyZ'];
        yield 'OpenAI project'   => ['sk-proj-AbCdEf1234567890XyZabcdefgh'];
        yield 'OpenAI classic'   => ['sk-AbCdEf1234567890XyZabcdefghij'];
        yield 'OpenRouter'       => ['sk-or-v1-a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6'];
        yield 'Google Gemini'    => ['AIzaSyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q'];
    }

    /**
     * @dataProvider keyFormats
     */
    public function testEveryPrefixedKeyFormatIsMaskedByPatternAlone(string $key): void
    {
        // No known secret passed: this is the net for keys we do not hold.
        $masked = CredentialMasker::mask("Request failed with $key at the endpoint");

        self::assertStringNotContainsString($key, $masked, 'key survived the pattern net');
    }

    public function testAnOpaqueKeyIsMaskedBecauseWeHoldIt(): void
    {
        // Mistral-shaped: no prefix, nothing a pattern could ever anchor on.
        // This is the case that made literal masking the primary mechanism
        // rather than a supplement.
        $key    = 'V9xQ2mL7pR4tZ8wN1cB6yH3jK5sD0aF';
        $masked = CredentialMasker::mask("Unauthorized: $key rejected", $key);

        self::assertStringNotContainsString($key, $masked);
        self::assertStringContainsString('***', $masked);
    }

    public function testAnOpaqueKeyWithoutTheLiteralIsNotMagicallyFound(): void
    {
        // Honesty about the limit: without the value, no pattern catches this.
        // Stating it here keeps the next reader from assuming more coverage than
        // exists — the mistake the previous README made about encryption.
        $key    = 'V9xQ2mL7pR4tZ8wN1cB6yH3jK5sD0aF';
        $masked = CredentialMasker::mask("Unauthorized: $key rejected");

        self::assertStringContainsString($key, $masked);
    }

    public function testAShortValueIsNotStruckFromOrdinaryProse(): void
    {
        // A two-character "secret" would blank out half a sentence.
        $masked = CredentialMasker::mask('Die Seite wurde nicht gefunden.', 'ei');

        self::assertSame('Die Seite wurde nicht gefunden.', $masked);
    }

    public function testTheLogContextCarriesNoCleartext(): void
    {
        // The old call sites passed ['exception' => $e], so the full message
        // reached the log while only the browser got a masked string.
        $key       = 'sk-proj-AbCdEf1234567890XyZabcdefgh';
        $exception = new \RuntimeException("Incorrect API key provided: $key");

        $context = CredentialMasker::context($exception, $key);

        self::assertStringNotContainsString($key, $context['message']);
        self::assertStringNotContainsString($key, $context['trace']);
        self::assertSame(\RuntimeException::class, $context['exception_class']);
    }

    public function testBearerTokensStillGoThroughTheNet(): void
    {
        $token  = 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9';
        $masked = CredentialMasker::mask("Auth header: $token");

        self::assertStringNotContainsString('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', $masked);
    }
}
