<?php

namespace Playbloom;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

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
}
