<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class ContaoAiBackendBundle extends AbstractBundle
{
    public function loadExtension(
        array $config,
        ContainerConfigurator $containerConfigurator,
        ContainerBuilder $containerBuilder,
    ): void {
        $containerConfigurator->import('../config/services.yaml');
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->extension('twig', [
            'paths' => [
                __DIR__ . '/Resources/views' => 'ContaoAiBackend',
            ],
        ]);
    }

    // Routes are loaded by the ContaoManager Plugin (RoutingPluginInterface)
    // to avoid duplicate registration with both AbstractBundle::configureRoutes
    // and the Plugin loader.
}
