<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit;

use Contao\CoreBundle\Config\ResourceFinder;
use Contao\CoreBundle\Controller\Backend\AbstractBackendController;
use Contao\CoreBundle\Event\ContaoCoreEvents;
use Contao\CoreBundle\Event\MenuEvent;
use Contao\CoreBundle\Twig\Loader\TemplateLocator;
use Contao\CoreBundle\Twig\Loader\ThemeNamespace;
use Doctrine\DBAL\Connection;
use Knp\Menu\MenuFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
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

    /**
     * The counter for the two scans below: an empty file list passes them just
     * as happily as a clean one.
     */
    public function testTheScansSeeTheSourceTree(): void
    {
        $files = $this->phpFilesIn(self::ROOT . '/src');

        self::assertGreaterThan(30, \count($files), 'src/ yields almost no PHP files — the scan path is wrong');
        self::assertArrayHasKey(
            realpath(self::ROOT . '/src/Controller/AiChatController.php'),
            array_flip(array_map('realpath', array_keys($files))),
            'the controller is not among the scanned files',
        );
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
     * "would Contao call it what we call it" — and Contao answers it: this asks
     * the real TemplateLocator, not a copy of its rule (review 2026-10-03: the
     * first version of this test re-implemented the rule, and would have stayed
     * green had Contao changed it).
     *
     * Not covered: that Contao picks up this bundle's contao/templates/ as a
     * source at all (its ResourceFinder does that by convention; here it gets an
     * empty one). That half is checked on the test server with
     * `debug:contao-twig backend/ai_chat`.
     */
    public function testContaoRegistersEachTemplateUnderTheRenderedName(): void
    {
        // No database here: the mocked connection yields no theme folders,
        // which only matter for templates/ in the project, not for a bundle.
        $locator = new TemplateLocator(
            sys_get_temp_dir(),
            new ResourceFinder([]),
            new ThemeNamespace(),
            $this->createMock(Connection::class),
        );

        $registered = array_keys($locator->findTemplates(self::TEMPLATE_DIR));
        $missing = [];

        foreach ($this->phpFilesIn(self::ROOT . '/src') as $path => $source) {
            if (!preg_match_all("#'@Contao/([^']+\.html\.twig)'#", $source, $m)) {
                continue;
            }

            foreach ($m[1] as $name) {
                if (!\in_array($name, $registered, true)) {
                    $missing[] = "@Contao/$name (rendered in " . basename($path) . ')';
                }
            }
        }

        self::assertNotEmpty($registered, 'the locator found no templates at all — wrong directory');
        self::assertSame(
            [],
            $missing,
            "Contao does not register these names. It has: " . implode(', ', $registered) . "\n  - " . implode("\n  - ", $missing),
        );
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

    // ── the template renders inside Contao's be_main ─────────────────────

    /**
     * A stand-in `be_main.html.twig` with the real block name. What this pins is
     * that the page fills `main_content` of the Twig be_main (Contao 5.7 and 6.x)
     * and renders nothing outside it. The real be_main is checked on the test
     * server, not here.
     */
    public function testThePageRendersInsideBeMain(): void
    {
        $parentDir = sys_get_temp_dir() . '/ai-chat-parent-' . bin2hex(random_bytes(4));
        mkdir($parentDir);
        $expectedMarker = 'PARENT-TWIG';

        try {
            file_put_contents("$parentDir/be_main.html.twig", $expectedMarker . '<{% block meta %}{% endblock %}>[{% block main_content %}{% endblock %}]');

            $html = $this->twig($parentDir)->render('@Contao/backend/ai_chat.html.twig', [
                'title' => 'AI chat', 'headline' => 'AI chat',
                'hasKey' => true, 'blocker' => null, 'platform' => 'anthropic',
                'tools' => ['page_read'], 'csrfToken' => 't', 'streamUrl' => '/contao/ai-stream',
            ]);
        } finally {
            array_map('unlink', glob("$parentDir/*") ?: []);
            rmdir($parentDir);
        }

        self::assertStringStartsWith($expectedMarker . '<', $html);
        self::assertMatchesRegularExpression('#<[^>]*<meta name="turbo-cache-control" content="no-cache">\s*>#', $html, 'the no-cache hint belongs in the meta block, i.e. in <head>');
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

        // The attribute's arguments rather than its getters, which are deprecated
        // since Symfony 7.4.
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

    public function testNoCurrentRequestIsNotAnError(): void
    {
        $factory = new MenuFactory();
        $tree = $factory->createItem('mainMenu');
        $tree->addChild($factory->createItem('system'));

        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn(true);
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/contao/ai-chat');

        (new BackendMenuListener($auth, $router, new RequestStack(), new IdentityTranslator()))(new MenuEvent($factory, $tree));

        $item = $tree->getChild('system')->getChild('ai_chat');
        self::assertNotNull($item);
        self::assertFalse($item->isCurrent());
    }

    public function testAMissingSystemGroupIsNotAnError(): void
    {
        $factory = new MenuFactory();
        $tree = $factory->createItem('mainMenu');

        $this->menuListener(granted: true, controller: null)(new MenuEvent($factory, $tree));

        self::assertCount(0, $tree->getChildren());
    }

    // ── wiring ───────────────────────────────────────────────────────────

    /**
     * The listener tests above build their objects themselves (review
     * 2026-10-03, rule 25). This checks the half that is visible without a
     * kernel: both classes get an autoconfigured definition from services.yaml,
     * and their attributes name the right event and priority.
     *
     * Whether Symfony turns the attribute into a registered listener needs the
     * compiled container — checked on the test server after each deploy with
     * `debug:event-dispatcher contao.backend_menu_build` and `kernel.request`.
     */
    public function testTheListenersAreWiredByServicesYaml(): void
    {
        $container = new ContainerBuilder();
        (new YamlFileLoader($container, new FileLocator(self::ROOT . '/config')))->load('services.yaml');

        $expected = [
            BackendMenuListener::class   => [ContaoCoreEvents::BACKEND_MENU_BUILD, -255],
            LegacyChatUrlListener::class => [KernelEvents::REQUEST, 16],
        ];

        foreach ($expected as $class => [$event, $priority]) {
            self::assertTrue($container->hasDefinition($class), "$class has no service definition");
            $definition = $container->getDefinition($class);
            self::assertFalse($definition->hasTag('container.excluded'), "$class is excluded from auto-discovery");
            self::assertTrue($definition->isAutoconfigured(), "#[AsEventListener] only works on an autoconfigured service");

            $attributes = (new \ReflectionClass($class))->getAttributes(AsEventListener::class);
            self::assertCount(1, $attributes, "$class needs exactly one #[AsEventListener]");
            $listener = $attributes[0]->newInstance();
            self::assertSame($event, $listener->event, "$class listens to the wrong event");
            self::assertSame($priority, $listener->priority, "$class has the wrong priority");
        }
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

    public function testOtherRoutesWithTheSameParameterAreNotRedirected(): void
    {
        $event = $this->requestEvent(['_route' => 'contao_frontend'], ['do' => 'ai_chat']);
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
