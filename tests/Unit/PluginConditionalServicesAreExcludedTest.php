<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Yaml\Yaml;

/**
 * A plugin-conditional service must be excluded from auto-discovery.
 *
 * 🔴 2026-09-04, on a live site, during an update:
 *
 *     Cannot autowire "…Tool\FaqTool": argument "$createCommand" needs
 *     "…Command\FaqCreateCommand" but this type has been excluded
 *
 * `EventTool` and `FaqTool` arrived in v0.6.0 and were registered in
 * `services_calendar.yaml` / `services_faq.yaml`, guarded by
 * `class_exists(FaqModel::class)` in `loadExtension()`. What was forgotten is
 * the other half: taking them out of the `../src/` auto-discovery in
 * `services.yaml`.
 *
 * 🎯 **The guard only prevents the *additional* registration. It does not
 * remove the class from auto-discovery.** Without an exclude entry the tool is
 * built on every installation — including those where its command does not
 * exist, because the core bundle excludes that command by the same mechanism.
 * The guard looks sufficient. It is exactly half of what is needed.
 *
 * The same installation supplied the control: `contao/news-bundle` is missing
 * there too, and `NewsTool` stayed silent — because it *was* excluded. Same
 * shape, opposite outcome, one line of configuration apart.
 *
 * ## Why a test and not a note
 *
 * Nothing fails locally: the test server has every optional bundle installed, so
 * the container builds and the suite passes. The defect is only visible on an
 * installation that lacks a plugin — which is to say, on someone else's site,
 * during their update. This test reproduces that condition from the
 * configuration alone.
 *
 * ## Why this asks Symfony instead of matching patterns itself (2026-09-05)
 *
 * ⚠️ **Do not replace the container below with `fnmatch` again.** The first
 * version did exactly that: it read the `exclude` list out of the YAML,
 * expanded `{a,b}` braces with a regex and matched them with `fnmatch` — and
 * its docblock claimed this behaved "the same way Symfony's glob handling
 * behaves". That claim was never verified anywhere.
 *
 * The patterns happen to agree today. The assumption stays, and it is
 * unbacked: `fnmatch` walks across directory separators without
 * `FNM_PATHNAME`, Symfony's glob handling does not do so everywhere. A future
 * pattern can drift apart from its reimplementation — and then this test passes
 * while the container breaks, which is the exact failure it exists to prevent.
 *
 * Loading `services.yaml` into a real `ContainerBuilder` removes the assumption:
 * whatever Symfony does with `resource` and `exclude`, this sees the result.
 *
 * 🎯 **Careful with the question you ask the container.** `hasDefinition()` is
 * the wrong one — Symfony keeps an abstract placeholder definition tagged
 * `container.excluded` for every excluded class and drops it only at compile
 * time. Asked that way, *every* tool looks present, excluded or not.
 */
class PluginConditionalServicesAreExcludedTest extends TestCase
{
    private const CONFIG_DIR = __DIR__ . '/../../config';

    private const NAMESPACE_PREFIX = 'Webwerkwien\\ContaoAiBackendBundle\\';

    /**
     * Services that exist only when a particular contao bundle is installed.
     *
     * Read from the plugin files rather than from a list in this test: that way
     * the next conditional service is covered the day it is registered, without
     * anyone remembering this file.
     *
     * @return array<string, string> class => the file that registers it
     */
    private function pluginConditionalClasses(): array
    {
        $found = [];

        foreach (glob(self::CONFIG_DIR . '/services_*.yaml') ?: [] as $file) {
            $parsed = Yaml::parseFile($file);

            foreach (array_keys($parsed['services'] ?? []) as $id) {
                // `_defaults`, `_instanceof` and interface entries are not services.
                if (!str_starts_with((string) $id, self::NAMESPACE_PREFIX)) {
                    continue;
                }

                $found[(string) $id] = basename($file);
            }
        }

        return $found;
    }

    /**
     * `services.yaml` alone, as Symfony reads it.
     *
     * Deliberately *not* through `ContaoAiBackendBundle::loadExtension()`: that
     * one imports the plugin files behind `class_exists()`, which asks the
     * autoloader, not the kernel. Since the optional contao bundles are dev
     * dependencies here, those classes resolve — so going through
     * `loadExtension()` would load the plugin files, register the tools
     * properly, and the assertion could never fail. The condition has to be
     * checked where it is decided.
     */
    private function autoDiscoveredContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $loader    = new YamlFileLoader($container, new FileLocator(self::CONFIG_DIR));
        $loader->load('services.yaml');

        return $container;
    }

    private function isExcluded(ContainerBuilder $container, string $class): bool
    {
        return $container->hasDefinition($class)
            && $container->getDefinition($class)->hasTag('container.excluded');
    }

    /**
     * The counter. A scanning test that matches nothing passes just as happily
     * as one that matches everything.
     */
    public function testThePluginConditionalFilesAreActuallyFound(): void
    {
        $classes = $this->pluginConditionalClasses();

        self::assertGreaterThanOrEqual(
            6,
            \count($classes),
            'expected at least the news/calendar/faq tools and rewriters — found: ' . implode(', ', array_keys($classes)),
        );

        self::assertGreaterThan(
            20,
            \count($this->autoDiscoveredContainer()->getDefinitions()),
            'services.yaml produced almost no definitions — the loader did not do its work',
        );
    }

    /**
     * The controls.
     *
     * Without these, "everything is excluded" is indistinguishable from "the
     * loader excluded everything" or "the loader loaded nothing at all". These
     * are ordinary services that must come out active; if one is reported
     * excluded, the measurement is broken, not the configuration.
     *
     * 🔴 **Every entry here must be a service that exists *only* through
     * auto-discovery.** The first version used `RecordRewriteTool` and
     * `ChatViewRenderer` — both carry their own explicit definition further down
     * in `services.yaml`, and an explicit definition overrides the exclude list.
     * Adding either to `exclude` changes nothing, so as controls they could not
     * fail: the mutation check that was meant to prove them stayed green.
     *
     * A control that cannot fail is not a control. Before adding one, check that
     * the class is not listed under `services:` in `services.yaml`.
     */
    private const CONTROLS = [
        'EventListener\ToolCallLogger',
        'Tool\PageTool',
        'Security\ToolAccessChecker',
    ];

    public function testOrdinaryServicesStayActive(): void
    {
        $container = $this->autoDiscoveredContainer();
        $explicit  = array_keys(Yaml::parseFile(self::CONFIG_DIR . '/services.yaml')['services'] ?? []);

        foreach (self::CONTROLS as $relative) {
            $class = self::NAMESPACE_PREFIX . $relative;

            self::assertNotContains(
                $class,
                $explicit,
                "$relative has an explicit definition and therefore cannot be excluded — useless as a control",
            );
            self::assertTrue($container->hasDefinition($class), "$relative is missing entirely — wrong container setup");
            self::assertFalse($this->isExcluded($container, $class), "$relative must not be excluded");
        }
    }

    public function testEveryPluginConditionalClassIsExcludedFromAutoDiscovery(): void
    {
        $container = $this->autoDiscoveredContainer();
        $leaked    = [];

        foreach ($this->pluginConditionalClasses() as $class => $file) {
            if (!$this->isExcluded($container, $class)) {
                $leaked[] = \sprintf('%s (registered in %s)', $class, $file);
            }
        }

        self::assertSame(
            [],
            $leaked,
            "These are registered conditionally but still picked up by auto-discovery in services.yaml.\n"
            . "On an installation without the matching contao bundle the container build fails:\n  - "
            . implode("\n  - ", $leaked),
        );
    }
}
