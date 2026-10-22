<?php

namespace Playbloom\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use Playbloom\Plugin\Authz\AuthzBundle;
use Playbloom\Plugin\Hookgate\HookgateBundle;
use Playbloom\Plugin\Plugins;
use Symfony\Bundle\TwigBundle\TwigBundle;

class PluginsTest extends TestCase
{
    public function testNamesAndClassesBecomeBundles(): void
    {
        $this->assertSame([
            AuthzBundle::class => ['all' => true],
            HookgateBundle::class => ['all' => true],
            TwigBundle::class => ['all' => true],
        ], Plugins::bundles(' authz , Hookgate,,' . TwigBundle::class));
    }

    public function testNoPluginsByDefault(): void
    {
        $this->assertSame([], Plugins::bundles(''));
    }

    public function testAnUnknownNameIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Plugins::bundles('authz,nonesuch');
    }
}
