<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle;

use Contao\CalendarEventsModel;
use Contao\FaqModel;
use Contao\NewsModel;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class ContaoAiBackendBundle extends AbstractBundle
{
    /** @param array<string, mixed> $config */
    public function loadExtension(
        array $config,
        ContainerConfigurator $containerConfigurator,
        ContainerBuilder $containerBuilder,
    ): void {
        $containerConfigurator->import('../config/services.yaml');

        // Plugin-bedingte Tools/Rewriter: Klassen referenzieren plugin-spezifische
        // Models (NewsModel, CalendarEventsModel, FaqModel) und die jeweiligen
        // *_update-Commands aus dem Core-Bundle. Ohne diese Guards bricht der
        // Container-Build, sobald das jeweilige contao-bundle fehlt.
        if (class_exists(NewsModel::class)) {
            $containerConfigurator->import('../config/services_news.yaml');
        }
        if (class_exists(CalendarEventsModel::class)) {
            $containerConfigurator->import('../config/services_calendar.yaml');
        }
        if (class_exists(FaqModel::class)) {
            $containerConfigurator->import('../config/services_faq.yaml');
        }
    }

    // No Twig path of its own any more. The chat template lives in
    // contao/templates/ and Contao registers it under @Contao, where it can be
    // overridden like any core template. Until 0.10.0 a prependExtension()
    // here added src/Resources/views as @ContaoAiBackend.

    // Routes are loaded by the ContaoManager Plugin (RoutingPluginInterface)
    // to avoid duplicate registration with both AbstractBundle::configureRoutes
    // and the Plugin loader.
}
