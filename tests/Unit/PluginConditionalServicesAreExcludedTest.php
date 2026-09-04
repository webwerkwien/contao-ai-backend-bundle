<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A plugin-conditional service must be excluded from auto-discovery.
 *
 * 🔴 2026-09-04, on wienerwandern.at — a live site, during an update:
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
 * Nothing fails locally: c5 has both bundles installed, so the container builds
 * and 129 tests pass. The defect is only visible on an installation that lacks
 * a plugin — which is to say, on someone else's site, during their update. This
 * test reproduces that condition from the configuration alone.
 */
class PluginConditionalServicesAreExcludedTest extends TestCase
{
    private const CONFIG_DIR = __DIR__ . '/../../config';

    private const NAMESPACE_PREFIX = 'Webwerkwien\\ContaoAiBackendBundle\\';

    /**
     * Service ids defined in the plugin-conditional files, i.e. everything that
     * only exists when a particular contao bundle is installed.
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

    /** @return list<string> raw exclude patterns from services.yaml */
    private function excludePatterns(): array
    {
        $parsed = Yaml::parseFile(self::CONFIG_DIR . '/services.yaml');
        $block  = $parsed['services'][self::NAMESPACE_PREFIX] ?? [];
        $exclude = $block['exclude'] ?? [];

        return array_values(array_map('strval', (array) $exclude));
    }

    /**
     * Does any exclude pattern cover the file this class lives in?
     *
     * Patterns are matched with `fnmatch`, the same way Symfony's `glob`
     * handling behaves for these entries, and the brace form
     * (`{A.php,B,C}`) is expanded first because `fnmatch` does not do braces.
     */
    private function isExcluded(string $class, array $patterns): bool
    {
        $relative = str_replace('\\', '/', substr($class, \strlen(self::NAMESPACE_PREFIX))) . '.php';

        foreach ($patterns as $pattern) {
            foreach ($this->expandBraces(trim($pattern, "'\"")) as $candidate) {
                $candidate = ltrim(str_replace('../src/', '', $candidate), '/');

                if (fnmatch($candidate, $relative) || fnmatch($candidate . '/*', $relative)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private function expandBraces(string $pattern): array
    {
        if (!preg_match('/^(.*)\{([^}]*)\}(.*)$/', $pattern, $m)) {
            return [$pattern];
        }

        $out = [];
        foreach (explode(',', $m[2]) as $part) {
            $out[] = $m[1] . trim($part) . $m[3];
        }

        return $out;
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

        self::assertNotEmpty($this->excludePatterns(), 'services.yaml has no exclude list at all');
    }

    public function testEveryPluginConditionalClassIsExcludedFromAutoDiscovery(): void
    {
        $patterns = $this->excludePatterns();
        $missing  = [];

        foreach ($this->pluginConditionalClasses() as $class => $file) {
            if (!$this->isExcluded($class, $patterns)) {
                $missing[] = \sprintf('%s (registered in %s)', $class, $file);
            }
        }

        self::assertSame(
            [],
            $missing,
            "These are registered conditionally but still picked up by auto-discovery in services.yaml.\n"
            . "On an installation without the matching contao bundle the container build fails:\n  - "
            . implode("\n  - ", $missing),
        );
    }
}
