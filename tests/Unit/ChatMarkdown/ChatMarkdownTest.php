<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\ChatMarkdown;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The chat shows the model's answer as Markdown (v0.14.0) with a renderer of its own
 * in the page template.
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
        // A harness that ran nothing would pass as well — count the cases, and an
        // emptied cases.json would match an empty run.
        $cases = \count(json_decode((string) file_get_contents(__DIR__ . '/cases.json'), true));
        $this->assertGreaterThanOrEqual(30, $cases);
        $this->assertSame($cases, substr_count($report, "ok  "), $report);
    }

    /**
     * The guard for the decision itself: the renderer builds every node with
     * createElement and textContent. A later "simplification" to innerHTML would
     * turn HTML in an answer into live markup.
     *
     * The raw template is searched, comments included: stripping comments by
     * pattern let a sink between a '/*' and a '*' . '/' in two string literals pass.
     * The back end loads MooTools, hence its set('html').
     */
    public function testThePageParsesNoStringAsHtml(): void
    {
        $template = (string) file_get_contents(self::TEMPLATE);

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'createContextualFragment', 'DOMParser', 'srcdoc', 'setAttribute(', "set('html'", 'set("html"', '.html(', 'eval(', 'Function('] as $sink) {
            $this->assertStringNotContainsString($sink, $template, $sink . ' must not be used on the chat page.');
        }

        // Known non-match: the renderer is there and uses the safe calls.
        $this->assertStringContainsString('const renderMarkdown', $template);
        $this->assertStringContainsString('replaceChildren(renderMarkdown(', $template);
    }

    public function testALinkOpensInANewTabWithoutTheOpener(): void
    {
        $template = (string) file_get_contents(self::TEMPLATE);

        // Which hrefs open is behaviour, checked in cases.json: harness.js resolves
        // every href like a browser and fails a link that leaves the host. A check
        // on the wording here would pass a hole next to the line and fail a correct
        // rewrite of it (before v0.14.0) — only the tab attributes
        // stay, the fake DOM keeps them as written.
        $this->assertStringContainsString("a.rel = 'noopener noreferrer'", $template);
    }
}
