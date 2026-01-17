<?php

namespace Tito10047\UX\TwigComponentSdc\Tests\Unit\CompilerPass;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Tito10047\UX\TwigComponentSdc\Attribute\Asset;
use Tito10047\UX\TwigComponentSdc\CompilerPass\AssetComponentCompilerPass;
use Tito10047\UX\TwigComponentSdc\Dto\ComponentAssetMap;

#[Asset(path: 'test.css', priority: 5)]
class MockComponent
{
}

final class AssetComponentCompilerPassTest extends TestCase
{
    public function testProcess(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('twig_component_sdc.auto_discovery', false);

        $mapDefinition = new Definition(ComponentAssetMap::class);
        $mapDefinition->setArgument('$map', []);
        $container->setDefinition('Tito10047\UX\TwigComponentSdc\Dto\ComponentAssetMap', $mapDefinition);

        $componentDefinition = new Definition(MockComponent::class);
        $componentDefinition->addTag('twig.component', ['key' => 'test_component']);
        $container->setDefinition('test_component_service', $componentDefinition);

        $pass = new AssetComponentCompilerPass();
        $pass->process($container);

        $map = $container->getDefinition('Tito10047\UX\TwigComponentSdc\Dto\ComponentAssetMap')->getArgument('$map');

        $this->assertArrayHasKey('test_component', $map);
        $this->assertSame('test.css', $map['test_component'][0]['path']);
        $this->assertSame(5, $map['test_component'][0]['priority']);
    }
}
