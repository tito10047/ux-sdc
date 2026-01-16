<?php

namespace Tito10047\UxTwigComponentAsset\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tito10047\UxTwigComponentAsset\DependencyInjection\UxTwigComponentAssetExtension;

class UxTwigComponentAssetExtensionTest extends TestCase
{
    public function testLoadSetsParameters(): void
    {
        $container = new ContainerBuilder();
        $extension = new UxTwigComponentAssetExtension();

        $extension->load([['component_namespace' => 'App\\Component\\']], $container);

        $this->assertTrue($container->hasParameter('ux_twig_component_asset.auto_discovery'));
        $this->assertEquals('%kernel.project_dir%/src_component', $container->getParameter('ux_twig_component_asset.ux_components_dir'));
        $this->assertEquals('App\\Component\\', $container->getParameter('ux_twig_component_asset.component_namespace'));
    }

    public function testPrependAddsConfiguration(): void
    {
        $container = new ContainerBuilder();
        $extension = new UxTwigComponentAssetExtension();

        $container->prependExtensionConfig('ux_twig_component_asset', ['component_namespace' => 'App\\Component\\']);

        $extension->prepend($container);

        $twigConfigs = $container->getExtensionConfig('twig');
        $this->assertNotEmpty($twigConfigs);
        $this->assertArrayHasKey('%kernel.project_dir%/src_component', $twigConfigs[0]['paths']);

        $assetMapperConfigs = $container->getExtensionConfig('framework');
        $this->assertNotEmpty($assetMapperConfigs);
        $this->assertContains('%kernel.project_dir%/src_component', $assetMapperConfigs[0]['asset_mapper']['paths']);

        $twigComponentConfigs = $container->getExtensionConfig('twig_component');
        $this->assertNotEmpty($twigComponentConfigs);
        $this->assertEquals('%kernel.project_dir%/src_component', $twigComponentConfigs[0]['defaults']['App\\Component\\']);
    }
}
