<?php

namespace Playbloom;

use Playbloom\Plugin\AbstractPlugin;
use Playbloom\Plugin\Plugins;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * One compiled container per set of plugins, so that changing
     * SATISFY_PLUGINS takes effect without clearing the cache.
     */
    public function getCacheDir(): string
    {
        $dir = $_SERVER['APP_CACHE_DIR'] ?? $this->getProjectDir() . '/var/cache/' . $this->environment;
        $plugins = Plugins::names();

        return [] === $plugins ? $dir : $dir . '/plugins-' . substr(sha1(implode(',', $plugins)), 0, 8);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $configDir = $this->getConfigDir();

        $container->import($configDir . '/{packages}/*.yaml');
        $container->import($configDir . '/{packages}/' . $this->environment . '/*.yaml');
        $container->import($configDir . '/services.yaml');
        // Satisfy 3 read its settings from config/parameters.yml, and deployments
        // mount their own. It overrides the defaults in services.yaml, as it did.
        $container->import($configDir . '/parameters.yml', null, 'not_found');
        $container->import($configDir . '/{services}_' . $this->environment . '.yaml');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $configDir = $this->getConfigDir();

        $routes->import($configDir . '/{routes}/' . $this->environment . '/*.yaml');
        $routes->import($configDir . '/{routes}/*.yaml');
        $routes->import($configDir . '/routes.yaml');
        foreach ($this->getBundles() as $bundle) {
            if ($bundle instanceof AbstractPlugin && null !== $path = $bundle->getControllerPath()) {
                $routes->import(['path' => $path, 'namespace' => $bundle->getNamespace() . '\\Controller'], 'attribute');
            }
        }
    }
}
