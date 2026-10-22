<?php

namespace Playbloom\Plugin;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * A Satisfy plugin: a bundle laid out as plugins/<Name>/{src,config,templates}.
 * While it is enabled its config/services.yaml is loaded and the controllers in
 * src/Controller/ get their routes; its templates are @<Name>/....
 */
abstract class AbstractPlugin extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $this->getPath() . '/config/services.yaml';
        if (is_file($services)) {
            $container->import($services);
        }
    }

    /**
     * The directory of the plugin's controllers, if it has any.
     */
    public function getControllerPath(): ?string
    {
        $path = $this->getPath() . '/src/Controller';

        return is_dir($path) ? $path : null;
    }
}
