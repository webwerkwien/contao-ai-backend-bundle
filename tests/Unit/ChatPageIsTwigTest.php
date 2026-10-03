<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Contao\CoreBundle\Event\MenuEvent;
use Knp\Menu\MenuFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Translation\IdentityTranslator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Webwerkwien\ContaoAiBackendBundle\Controller\AiChatController;
use Webwerkwien\ContaoAiBackendBundle\EventListener\BackendMenuListener;
use Webwerkwien\ContaoAiBackendBundle\EventListener\LegacyChatUrlListener;

/**
 * The chat page is a Twig template behind a Symfony route — nothing legacy left.
 *
 * 🔴 Issue #26, reported 2026-09-23 on Contao 6.0.0 with v0.9.3:
 *
 *     Template "@Contao/be_ai_chat.html.twig" is not defined.
 *
 * The chat was a legacy `BackendModule` (`BE_MOD['callback']`) with a
 * `be_ai_chat.html5` wrapper. Contao 6 renders legacy modules through Twig only
 * and never reads `.html5`. Contao converted its own wrappers in 6.0
 * (`be_maintenance.html5` became `be_maintenance.html.twig`); ours was not, and
 * the 6.0 test installation is reachable from the console only, so nobody ever
 * clicked the menu entry there.
 *
 * 0.10.0 drops the legacy path instead of patching the wrapper: a route
 * (AiChatController), a menu listener, and the template in contao/templates/ so
 * Contao can find and override it. These tests pin each of those pieces, and the
 * one thing the rebuild must not lose: the "Allowed modules" checkbox.
 */
class ChatPageIsTwigTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const TEMPLATE_DIR = self::ROOT . '/contao/templates';

    // ── nothing legacy left ──────────────────────────────────────────────

    public function testNoLegacyTemplatesAreShipped(): void
    {
        $html5 = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::TEMPLATE_DIR, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.html5')) {
                $html5[] = $file->getFilename();
            }
        }

        self::assertSame([], $html5, 'Contao 6 never reads .html5 — a template here works on 5.x and breaks on 6');
    }

    public function testNoClassRendersThroughALegacyTemplate(): void
    {
        $legacy = [];

        foreach ($this->phpFilesIn(self::ROOT . '/src') as $path => $source) {
            if (preg_match('/\$strTemplate\b|extends\s+(\\\\?Contao\\\\)?Backend(Module)?\b/', $source)) {
                $legacy[] = basename($path);
            }
        }

        self::assertSame([], $legacy, 'a $strTemplate or a BackendModule subclass renders through the legacy chain, which Contao 6 cannot render from .html5');
    }

    public function testTheOwnTwigNamespaceIsGone(): void
    {
        self::assertDirectoryDoesNotExist(self::ROOT . '/src/Resources/views', 'templates belong in contao/templates/, where Contao looks for them');

        foreach ([...$this->phpFilesIn(self::ROOT . '/src'), ...$this->phpFilesIn(self::ROOT . '/contao')] as $path => $source) {
            self::assertStringNotContainsString('@ContaoAiBackend/', $source, basename($path) . ' still renders from the old namespace');
        }
    }

    /**
     * Every `@Contao/…html.twig` the code renders must be shipped. The counter
     * makes sure the scan found the chat page at all.
     */
    public function testEveryRenderedContaoTemplateIsShipped(): void
    {
        $referenced = [];

        foreach ($this->phpFilesIn(self::ROOT . '/src') as $path => $source) {
            if (preg_match_all("#'@Contao/([^']+\.html\.twig)'#", $source, $m)) {
                foreach ($m[1] as $name) {
                    $referenced[$name] = basename($path);
                }
            }
        }

        self::assertArrayHasKey('backend/ai_chat.html.twig', $referenced, 'the scan did not find the chat page — regex or path wrong');

        foreach ($referenced as $name => $file) {
            self::assertFileExists(self::TEMPLATE_DIR . '/' . $name, "$file renders @Contao/$name");
        }
    }

    /**
     * 🔴 The check above passed while the page could not open — on the test
     * server, 2026-10-03. The file existed; Contao registered it as
     * `@Contao/ai_chat.html.twig`, not under the name the controller renders.
     *
     * Contao's TemplateLocator reads a bundle's template directory one level
     * deep and keeps subfolders in the name only below a `.twig-root` marker
     * (5.3, 5.7 and 6.0 alike). So the question is not "is the file there" but
     * "would Contao call it what we call it" — answered here by Contao's rule,
     * not by a file lookup.
     */
    public function testContaoRegistersEachTemplateUnderTheRenderedName(): void
    {
        $wrong = [];

        foreach ($this->phpFilesIn(self::ROOT . '/src') as $path => $source) {
            if (!preg_match_all("#'@Contao/([^']+\.html\.twig)'#", $source, $m)) {
                continue;
            }

            foreach ($m[1] as $name) {
                if (str_contains($name, '/') && !is_file(self::TEMPLATE_DIR . '/.twig-root')) {
                    $wrong[] = "@Contao/$name would be registered as @Contao/" . basename($name) . ' (rendered in ' . basename($path) . ')';
                }
            }
        }

        self::assertSame([], $wrong, "contao/templates/.twig-root is missing:\n  - " . implode("\n  - ", $wrong));
    }

    /**
     * The marker is a dot file, and dot files are what an export-ignore rule
     * tends to sweep up. Kept out of the package, it would be present here and
     * missing on every installation.
     */
    public function testTheMarkerIsShippedWithThePackage(): void
    {
        $marker = '/contao/templates/.twig-root';

        foreach (file(self::ROOT . '/.gitattributes', \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (!preg_match('/^(\S+)\s+.*\bexport-ignore\b/', $line, $m)) {
                continue;
            }

            $pattern = $m[1];
            $hit = fnmatch($pattern, $marker) || fnmatch(ltrim($pattern, '/'), ltrim($marker, '/'))
                || ('/' === $pattern[0] && str_starts_with($marker, rtrim($pattern, '/') . '/'));

            self::assertFalse($hit, ".gitattributes rule `$line` keeps the marker out of the package");
        }
    }

    // ── the template finds its parent on every supported Contao ─────────

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function parentLayouts(): iterable
    {
        // What each installation resolves, measured 2026-10-03 on the test server.
        yield 'Contao 5.3: only the legacy be_main' => [['be_main.html5' => 'PARENT-HTML5'], 'PARENT-HTML5'];
        yield 'Contao 6.0: only the Twig be_main' => [['be_main.html.twig' => 'PARENT-TWIG'], 'PARENT-TWIG'];
        yield 'Contao 5.7: both, Twig wins' => [['be_main.html.twig' => 'PARENT-TWIG', 'be_main.html5' => 'PARENT-HTML5'], 'PARENT-TWIG'];
    }

    /**
     * The real Contao turns `be_main.html5` into something Twig can extend; here
     * a stand-in with the same name and block does. What this pins is the
     * fallback order of `{% extends [...] %}` — that the page renders inside
     * whichever parent the installation has. Contao's own .html5 handling is
     * checked on the test server, not here.
     *
     * @param array<string, string> $parents
     *
     * @dataProvider parentLayouts
     */
    public function testThePageRendersInsideWhicheverParentExists(array $parents, string $expectedMarker): void
    {
        $parentDir = sys_get_temp_dir() . '/ai-chat-parent-' . bin2hex(random_bytes(4));
        mkdir($parentDir);

        try {
            foreach ($parents as $file => $marker) {
                file_put_contents("$parentDir/$file", $marker . '[{% block main_content %}{% endblock %}]');
            }

            $html = $this->twig($parentDir)->render('@Contao/backend/ai_chat.html.twig', [
                'title' => 'AI chat', 'headline' => 'AI chat',
                'hasKey' => true, 'blocker' => null, 'platform' => 'anthropic',
                'tools' => ['page_read'], 'csrfToken' => 't', 'streamUrl' => '/contao/ai-stream',
            ]);
        } finally {
            array_map('unlink', glob("$parentDir/*") ?: []);
            rmdir($parentDir);
        }

        self::assertStringStartsWith($expectedMarker . '[', $html);
        self::assertStringContainsString('id="ai-chat-root"', $html, 'the chat must sit inside main_content');
        self::assertStringEndsWith(']', trim($html), 'nothing may be rendered outside the parent');
    }

    // ── the permission checkbox survives ─────────────────────────────────

    /**
     * Contao builds "Allowed modules" from BE_MOD and skips entries with
     * `disablePermissionChecks` (tl_user::getModules(), 5.3 and 6.0 alike).
     * AiAccessVoter grants the chat to editors through that checkbox — drop the
     * entry or set that flag, and only admins can use the chat any more.
     */
    public function testTheModuleEntryStillCarriesThePermission(): void
    {
        $backup = $GLOBALS['BE_MOD'] ?? null;
        $GLOBALS['BE_MOD'] = [];

        try {
            include self::ROOT . '/contao/config/config.php';
            $entry = $GLOBALS['BE_MOD']['system']['ai_chat'] ?? null;
        } finally {
            $GLOBALS['BE_MOD'] = $backup;
        }

        self::assertIsArray($entry, 'without the BE_MOD entry there is no "Allowed modules" checkbox');
        self::assertArrayNotHasKey('disablePermissionChecks', $entry, 'this flag removes the checkbox');
        self::assertTrue($entry['hideInNavigation'] ?? false, 'the menu entry comes from BackendMenuListener; a second one would point at the legacy address');
        self::assertArrayNotHasKey('callback', $entry, 'a callback is the legacy path this rebuild removed');
        self::assertArrayNotHasKey('tables', $entry);
    }

    // ── route ────────────────────────────────────────────────────────────

    public function testTheControllerIsAContaoBackendPage(): void
    {
        $parent = (new \ReflectionClass(AiChatController::class))->getParentClass();

        self::assertSame(
            AbstractBackendController::class,
            false === $parent ? null : $parent->getName(),
            'only Contao\'s base adds the be_main context',
        );

        // The attribute's arguments rather than its getters: those are deprecated
        // since Symfony 7.4, and the properties replacing them do not exist in 6.4.
        $args = (new \ReflectionMethod(AiChatController::class, 'index'))->getAttributes(Route::class)[0]->getArguments();

        self::assertSame('%contao.backend.route_prefix%/ai-chat', $args[0] ?? $args['path'] ?? null, 'follow the configured back-end prefix');
        self::assertSame('backend', $args['defaults']['_scope'] ?? null);
    }

    // ── menu ─────────────────────────────────────────────────────────────

    public function testAGrantedUserGetsTheEntryInTheSystemGroup(): void
    {
        $tree = $this->runMenuListener(granted: true, controller: AiChatController::class . '::index');
        $item = $tree->getChild('system')?->getChild('ai_chat');

        self::assertNotNull($item);
        self::assertSame('/contao/ai-chat', $item->getUri());
        self::assertSame('MOD.ai_chat.0', $item->getLabel());
        self::assertTrue($item->isCurrent(), 'on the chat page the entry is the active one');
    }

    public function testTheEntryIsNotActiveElsewhere(): void
    {
        $tree = $this->runMenuListener(granted: true, controller: 'Contao\CoreBundle\Controller\BackendController::mainAction');

        self::assertFalse($tree->getChild('system')->getChild('ai_chat')->isCurrent());
    }

    public function testAUserWithoutTheGrantGetsNoEntry(): void
    {
        $tree = $this->runMenuListener(granted: false, controller: null);

        self::assertNull($tree->getChild('system')->getChild('ai_chat'));
    }

    public function testOtherMenusAreLeftAlone(): void
    {
        $tree = $this->runMenuListener(granted: true, controller: null, treeName: 'headerMenu');

        self::assertNull($tree->getChild('system')->getChild('ai_chat'));
    }

    public function testAMissingSystemGroupIsNotAnError(): void
    {
        $factory = new MenuFactory();
        $tree = $factory->createItem('mainMenu');

        $this->menuListener(granted: true, controller: null)(new MenuEvent($factory, $tree));

        self::assertCount(0, $tree->getChildren());
    }

    // ── old address ──────────────────────────────────────────────────────

    public function testTheOldAddressIsRedirected(): void
    {
        $event = $this->requestEvent(['_route' => 'contao_backend'], ['do' => 'ai_chat']);
        $this->legacyListener()($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/contao/ai-chat', $response->getTargetUrl());
    }

    public function testOtherModulesAreNotRedirected(): void
    {
        $event = $this->requestEvent(['_route' => 'contao_backend'], ['do' => 'page']);
        $this->legacyListener()($event);

        self::assertNull($event->getResponse());
    }

    public function testSubRequestsAreNotRedirected(): void
    {
        $event = $this->requestEvent(['_route' => 'contao_backend'], ['do' => 'ai_chat'], HttpKernelInterface::SUB_REQUEST);
        $this->legacyListener()($event);

        self::assertNull($event->getResponse());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @return array<string, string> path => source
     */
    private function phpFilesIn(string $dir): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ('php' === $file->getExtension()) {
                $out[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $out;
    }

    private function twig(string $parentDir): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(self::TEMPLATE_DIR, 'Contao');
        $loader->addPath($parentDir, 'Contao');

        $twig = new Environment($loader, ['autoescape' => 'html', 'strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('csp_nonce', static fn (): string => 'nonce'));

        return $twig;
    }

    private function menuListener(bool $granted, ?string $controller): BackendMenuListener
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn($granted);

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->with('contao_ai_backend_chat')->willReturn('/contao/ai-chat');

        $stack = new RequestStack();
        $stack->push(new Request(attributes: null === $controller ? [] : ['_controller' => $controller]));

        return new BackendMenuListener($auth, $router, $stack, new IdentityTranslator());
    }

    private function runMenuListener(bool $granted, ?string $controller, string $treeName = 'mainMenu'): \Knp\Menu\ItemInterface
    {
        $factory = new MenuFactory();
        $tree = $factory->createItem($treeName);
        $tree->addChild($factory->createItem('system'));

        $this->menuListener($granted, $controller)(new MenuEvent($factory, $tree));

        return $tree;
    }

    private function legacyListener(): LegacyChatUrlListener
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->with('contao_ai_backend_chat')->willReturn('/contao/ai-chat');

        return new LegacyChatUrlListener($router);
    }

    /**
     * @param array<string, string> $attributes
     * @param array<string, string> $query
     */
    private function requestEvent(array $attributes, array $query, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->createMock(HttpKernelInterface::class), new Request($query, [], $attributes), $type);
    }
}
