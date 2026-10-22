<?php

namespace Playbloom\Plugin;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * plugin_admin_links(): the admin home page's buttons from enabled plugins.
 */
class PluginLinksExtension extends AbstractExtension
{
    /**
     * @param iterable<AdminLinkInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('satisfy.admin_link')]
        private readonly iterable $providers,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('plugin_admin_links', [$this, 'getLinks'])];
    }

    /**
     * @return array<string, string> label => route name
     */
    public function getLinks(): array
    {
        $links = [];
        foreach ($this->providers as $provider) {
            $links += $provider->getAdminLinks();
        }

        return $links;
    }
}
