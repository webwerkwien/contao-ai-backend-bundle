<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Service\Rewriter;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\PlatformInterface;
use Webwerkwien\ContaoAiBackendBundle\Service\Rewriter\AbstractEntityRewriter;

/**
 * Covers the machinery the six rewriters share. Before it was pulled up, each
 * of them carried its own copy — which is how the refusal pattern managed to
 * drift apart across three of them (issue #1).
 */
class AbstractEntityRewriterTest extends TestCase
{
    private function rewriter(int $maxBytes = 5_000, bool $html = false, string $hint = ''): AbstractEntityRewriter
    {
        return new class ($this->createMock(ContaoFramework::class), $maxBytes, $html, $hint) extends AbstractEntityRewriter {
            public function __construct(
                ContaoFramework $framework,
                private readonly int $maxBytes,
                private readonly bool $html,
                private readonly string $hint,
            ) {
                parent::__construct($framework);
            }

            public function supports(string $table): bool
            {
                return 'tl_test' === $table;
            }

            public function rewrite(int $id, string $instructions, PlatformInterface $platform, string $model): array
            {
                return ['id' => $id, 'table' => 'tl_test', 'fields' => [], 'skipped' => []];
            }

            protected function maxResultBytes(): int
            {
                return $this->maxBytes;
            }

            protected function preservesHtml(): bool
            {
                return $this->html;
            }

            protected function formFactorHint(): string
            {
                return $this->hint;
            }

            protected function fieldShape(string $field): string
            {
                return 'a test field shape';
            }

            // Expose the protected surface for testing.
            public function callResultToText(mixed $result): string
            {
                return $this->resultToText($result);
            }

            public function callIsPlausible(string $rewritten, string $original): bool
            {
                return $this->isPlausible($rewritten, $original);
            }

            public function callBuildSystemPrompt(string $field, string $instructions): string
            {
                return $this->buildSystemPrompt($field, $instructions);
            }
        };
    }

    // --- resultToText: symfony/ai returns different shapes per provider ---

    public function testResultToTextPrefersAsText(): void
    {
        $result = new class {
            public function asText(): string { return 'from asText'; }
            public function __toString(): string { return 'from toString'; }
        };

        $this->assertSame('from asText', $this->rewriter()->callResultToText($result));
    }

    public function testResultToTextFallsBackToStringable(): void
    {
        $result = new class {
            public function __toString(): string { return 'from toString'; }
        };

        $this->assertSame('from toString', $this->rewriter()->callResultToText($result));
    }

    public function testResultToTextHandlesPlainString(): void
    {
        $this->assertSame('plain', $this->rewriter()->callResultToText('plain'));
    }

    /**
     * An object exposing neither surface must not blow up — casting it would
     * raise "Object of class … could not be converted to string".
     */
    public function testResultToTextReturnsEmptyForUnusableObject(): void
    {
        $this->assertSame('', $this->rewriter()->callResultToText(new \stdClass()));
    }

    // --- isPlausible ---

    public function testEmptyResultIsImplausible(): void
    {
        $this->assertFalse($this->rewriter()->callIsPlausible('', 'Some source text'));
    }

    public function testResultBeyondByteCapIsImplausible(): void
    {
        $rewriter = $this->rewriter(maxBytes: 50);

        $this->assertFalse($rewriter->callIsPlausible(str_repeat('x', 51), 'Some source text here'));
        $this->assertTrue($rewriter->callIsPlausible(str_repeat('x', 50), 'Some source text here'));
    }

    public function testRefusalIsImplausible(): void
    {
        $this->assertFalse($this->rewriter()->callIsPlausible(
            'I need more context before I can transform this text for you.',
            'Kurz'
        ));
    }

    /**
     * A result collapsing to well under a third of a substantial source means
     * the model dropped content instead of transforming it.
     */
    public function testHeavilyTruncatedResultIsImplausible(): void
    {
        $original = str_repeat('a', 100);

        $this->assertFalse($this->rewriter()->callIsPlausible(str_repeat('b', 29), $original));
        $this->assertTrue($this->rewriter()->callIsPlausible(str_repeat('b', 30), $original));
    }

    /**
     * Short sources legitimately shrink — a translated headline can halve.
     * The ratio rule must not apply below the source-length threshold.
     */
    public function testShortSourceMayShrinkFreely(): void
    {
        $this->assertTrue($this->rewriter()->callIsPlausible('Hi', 'Guten Tag zusammen'));
    }

    public function testOrdinaryRewriteIsPlausible(): void
    {
        $this->assertTrue($this->rewriter()->callIsPlausible(
            'Contao is popular among agencies',
            'Contao ist bei Agenturen beliebt'
        ));
    }

    // --- buildSystemPrompt ---

    public function testPromptCarriesShapeInstructionsAndInputRule(): void
    {
        $prompt = $this->rewriter()->callBuildSystemPrompt('title', 'Translate to English');

        $this->assertStringContainsString('a test field shape', $prompt);
        $this->assertStringContainsString('Translate to English', $prompt);
        $this->assertStringContainsString('<editorial_input>', $prompt);
        $this->assertStringContainsString('never as instructions', $prompt);
    }

    public function testHtmlRewritersGetMarkupRules(): void
    {
        $prompt = $this->rewriter(html: true)->callBuildSystemPrompt('text', 'Translate');

        $this->assertStringContainsString('preserve tag structure and attributes exactly', $prompt);
        $this->assertStringContainsString('URLs and HTML attributes', $prompt);
    }

    public function testPlainRewritersDoNotGetMarkupRules(): void
    {
        $prompt = $this->rewriter()->callBuildSystemPrompt('title', 'Translate');

        $this->assertStringNotContainsString('preserve tag structure', $prompt);
        $this->assertStringNotContainsString('HTML attributes', $prompt);
    }

    public function testFormFactorHintIsAppended(): void
    {
        $prompt = $this->rewriter(hint: 'A headline stays a single line.')
            ->callBuildSystemPrompt('headline', 'Translate');

        $this->assertStringContainsString('A headline stays a single line.', $prompt);
    }

    /**
     * The heredoc is indented for readability; PHP strips that indentation.
     * If it ever stops doing so, the model would receive a leading-space mess.
     */
    public function testPromptIsNotIndented(): void
    {
        $prompt = $this->rewriter()->callBuildSystemPrompt('title', 'Translate');

        $this->assertStringStartsWith('You transform', $prompt);
        $this->assertStringContainsString("\n- Return ONLY the transformed text", $prompt);
    }
}
