<?php

namespace Tito10047\UX\Sdc\Tests\Unit\CompilerPass;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Tito10047\UX\Sdc\Attribute\Asset;
use Tito10047\UX\Sdc\CompilerPass\AssetComponentCompilerPass;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;

#[Asset(path: 'test.css', priority: 5)]
class MockComponent
{
}

final class AssetComponentCompilerPassTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        $this->cachePath = sys_get_temp_dir() . '/compiler_pass_test_metadata.php';
        if (file_exists($this->cachePath)) {
            unlink($this->cachePath);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->cachePath)) {
            unlink($this->cachePath);
        }
    }

    public function testProcess(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('sdc.auto_discovery', false);

        $registryDefinition = new Definition(SdcMetadataRegistry::class);
        $registryDefinition->setArgument('$cachePath', $this->cachePath);
        $container->setDefinition(SdcMetadataRegistry::class, $registryDefinition);

        $componentDefinition = new Definition(MockComponent::class);
        $componentDefinition->addTag('twig.component', ['key' => 'test_component']);
        $container->setDefinition('test_component_service', $componentDefinition);

        $pass = new AssetComponentCompilerPass();
        $pass->process($container);

        $this->assertFileExists($this->cachePath);
        $map = require $this->cachePath;

        $this->assertArrayHasKey('test_component', $map);
        $this->assertSame('test.css', $map['test_component'][0]['path']);
        $this->assertSame(5, $map['test_component'][0]['priority']);
    }
}
