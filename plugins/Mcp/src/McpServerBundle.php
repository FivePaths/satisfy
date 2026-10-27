<?php

namespace Playbloom\Plugin\Mcp;

use Playbloom\Plugin\AbstractPlugin;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * An MCP server at /admin/mcp, for agents such as Claude Code: tools over
 * satis.json and the build queue, behind a bearer token.
 *
 * Symfony's MCP bundle serves the protocol; this plugin enables it, names the
 * tools it exposes, and checks the token in Security\BearerTokenListener.
 */
class McpServerBundle extends AbstractPlugin
{
    public const PATH = '/admin/mcp';

    public static function requiredBundles(): array
    {
        return [McpBundle::class];
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('mcp', [
            'servers' => [
                'satisfy' => [
                    'http' => ['path' => self::PATH],
                    'registry' => ['tools' => [__NAMESPACE__ . '\\Tool\\']],
                ],
            ],
        ]);
    }
}
