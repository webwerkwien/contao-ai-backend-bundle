<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;
use Webwerkwien\ContaoAiBackendBundle\ContaoAiBackendBundle;
use Webwerkwien\ContaoAiCoreBundle\ContaoAiCoreBundle;

class Plugin implements BundlePluginInterface, RoutingPluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(ContaoAiBackendBundle::class)
                ->setLoadAfter([
                    ContaoCoreBundle::class,
                    ContaoAiCoreBundle::class,
                ]),
        ];
    }

    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): ?RouteCollection
    {
        $file = \dirname(__DIR__, 2) . '/config/routes.yaml';

        return $resolver->resolve($file)->load($file);
    }
}
