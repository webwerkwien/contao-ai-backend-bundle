<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\ChatMarkdown;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The chat shows the model's answer as Markdown (v0.14.0) with a renderer of its own
 * in the page template, decided with the maintainer on 2026-10-11.
 *
 * Two promises: the rendering is right (cases.json, run through the template's real
 * renderMarkdown against a minimal DOM in harness.js), and nothing on the page ever
 * parses a string as HTML — an answer can quote page content, i.e. text this
 * installation did not author.
 */
class ChatMarkdownTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../contao/templates/backend/ai_chat.html.twig';

    public function testTheRendererProducesTheExpectedNodes(): void
    {
        $node = (new ExecutableFinder())->find('node');

        if (null === $node) {
            $this->markTestSkipped('node is not installed; the Markdown cases run with it.');
        }

        exec(
            escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/harness.js') . ' '
                . escapeshellarg(self::TEMPLATE) . ' ' . escapeshellarg(__DIR__ . '/cases.json') . ' 2>&1',
            $output,
            $code,
        );

        $report = implode("\n", $output);

        $this->assertSame(0, $code, $report);
        // A harness that ran nothing would pass as well — count the cases.
        $this->assertSame(\count(json_decode((string) file_get_contents(__DIR__ . '/cases.json'), true)), substr_count($report, "ok  "), $report);
    }

    /**
     * The guard for the decision itself: the renderer builds every node with
     * createElement and textContent. A later "simplification" to innerHTML would
     * turn HTML in an answer into live markup.
     */
    public function testThePageParsesNoStringAsHtml(): void
    {
        $template = (string) file_get_contents(self::TEMPLATE);

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'createContextualFragment', 'DOMParser'] as $sink) {
            $this->assertStringNotContainsString($sink, preg_replace('/\{#.*?#\}|\/\*.*?\*\//s', '', $template), $sink . ' must not be used on the chat page.');
        }

        // Known non-match: the renderer is there and uses the safe calls.
        $this->assertStringContainsString('const renderMarkdown', $template);
        $this->assertStringContainsString('replaceChildren(renderMarkdown(', $template);
    }

    public function testALinkOpensOnlyForHttpAndPathsOnThisHost(): void
    {
        $template = (string) file_get_contents(self::TEMPLATE);

        $this->assertMatchesRegularExpression('#/\^https\?:\\\\/\\\\//i\.test\(u\)#', $template);
        $this->assertStringContainsString("u.startsWith('/') && !u.startsWith('//')", $template);
        $this->assertStringContainsString("a.rel = 'noopener noreferrer'", $template);
    }
}
