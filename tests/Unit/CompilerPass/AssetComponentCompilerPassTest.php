<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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
        $container = $this->createContainer(metadataCache: true);

        $pass = new AssetComponentCompilerPass();
        $pass->process($container);

        $this->assertFileExists($this->cachePath);
        $map = require $this->cachePath;

        $this->assertArrayHasKey(MockComponent::class, $map);
        $this->assertSame('test.css', $map[MockComponent::class][0]['path']);
        $this->assertSame(5, $map[MockComponent::class][0]['priority']);
    }

    public function testNothingIsDumpedWithoutMetadataCache(): void
    {
        $container = $this->createContainer(metadataCache: false);

        (new AssetComponentCompilerPass())->process($container);

        $this->assertFileDoesNotExist($this->cachePath);
    }

    /**
     * Switching `metadata_cache` from true to false rebuilds the container in the
     * same cache directory: the file of the previous build must not survive it.
     */
    public function testStaleCacheIsRemovedWithoutMetadataCache(): void
    {
        (new AssetComponentCompilerPass())->process($this->createContainer(metadataCache: true));
        $this->assertFileExists($this->cachePath);

        (new AssetComponentCompilerPass())->process($this->createContainer(metadataCache: false));

        $this->assertFileDoesNotExist($this->cachePath);
    }

    private function createContainer(bool $metadataCache): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('ux_sdc.auto_discovery', false);
        $container->setParameter(AssetComponentCompilerPass::METADATA_CACHE_PARAMETER, $metadataCache);

        $registryDefinition = new Definition(SdcMetadataRegistry::class);
        $registryDefinition->setArgument('$cachePath', $this->cachePath);
        $container->setDefinition(SdcMetadataRegistry::class, $registryDefinition);

        $componentDefinition = new Definition(MockComponent::class);
        $componentDefinition->addTag('twig.component', ['key' => 'test_component']);
        $container->setDefinition('test_component_service', $componentDefinition);

        return $container;
    }
}
