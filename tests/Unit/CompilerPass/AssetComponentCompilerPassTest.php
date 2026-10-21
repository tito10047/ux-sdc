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
use Tito10047\UX\Sdc\Service\ComponentMetadataResolver;
use Tito10047\UX\Sdc\Tests\Unit\Service\Fixtures\Alert\Alert;

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
        $this->assertSame('test.css', $map[MockComponent::class]['assets'][0]['path']);
        $this->assertSame(5, $map[MockComponent::class]['assets'][0]['priority']);
        $this->assertNull($map[MockComponent::class]['template']);
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

    public function testDumpsTheTemplateNextToTheClass(): void
    {
        $container = $this->createContainer(metadataCache: true, autoDiscovery: true, component: Alert::class);
        $container->setParameter('twig.default_path', \dirname(__DIR__) . '/Service/Fixtures');

        (new AssetComponentCompilerPass())->process($container);

        $map = require $this->cachePath;
        $this->assertSame('Alert/Alert.html.twig', $map[Alert::class]['template']);
        $this->assertSame(['vendor/explicit.css', 'Alert/Alert.css', 'Alert/Alert.js'], array_column($map[Alert::class]['assets'], 'path'));
        $this->assertSame([], $container->getCompiler()->getLog());
    }

    /**
     * There is no `logger` service while the container is built: what the
     * resolver has to leave out goes to the compiler log, which the kernel keeps
     * for debug containers and the profiler shows.
     */
    public function testFilesOutsideEveryTwigPathAreReportedToTheCompilerLog(): void
    {
        $container = $this->createContainer(metadataCache: true, autoDiscovery: true, component: Alert::class);

        (new AssetComponentCompilerPass())->process($container);

        $map = require $this->cachePath;
        $this->assertSame(['vendor/explicit.css'], array_column($map[Alert::class]['assets'], 'path'));
        $this->assertNull($map[Alert::class]['template']);

        $log = $container->getCompiler()->getLog();
        $this->assertCount(3, $log);
        $this->assertStringStartsWith(AssetComponentCompilerPass::class . ': [warning] Component "' . Alert::class . '": "' . realpath(\dirname(__DIR__) . '/Service/Fixtures/Alert/Alert.css') . '" next to the class is outside every Twig path (none configured)', $log[0]);
    }

    /**
     * The runtime resolver looks files up in the same Twig paths as the
     * build-time one, `twig.paths` of the application included.
     */
    public function testGivesTheRuntimeResolverTheTwigPaths(): void
    {
        $container = $this->createContainer(metadataCache: false);
        $container->register(ComponentMetadataResolver::class)->setArgument('$twigRoots', []);
        $container->setParameter('twig.default_path', __DIR__);
        $container->registerExtension(new class () extends \Symfony\Component\DependencyInjection\Extension\Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'twig';
            }
        });
        $container->loadFromExtension('twig', ['paths' => [\dirname(__DIR__) . '/Service/Fixtures/' => 'Fixtures', \dirname(__DIR__) . '/../Twig']]);

        (new AssetComponentCompilerPass())->process($container);

        $this->assertSame(
            [realpath(__DIR__), realpath(\dirname(__DIR__) . '/Service/Fixtures'), realpath(\dirname(__DIR__) . '/../Twig')],
            $container->getDefinition(ComponentMetadataResolver::class)->getArgument('$twigRoots'),
        );
    }

    /**
     * @param class-string $component
     */
    private function createContainer(bool $metadataCache, bool $autoDiscovery = false, string $component = MockComponent::class): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('ux_sdc.auto_discovery', $autoDiscovery);
        $container->setParameter(AssetComponentCompilerPass::METADATA_CACHE_PARAMETER, $metadataCache);

        $registryDefinition = new Definition(SdcMetadataRegistry::class);
        $registryDefinition->setArgument('$cachePath', $this->cachePath);
        $container->setDefinition(SdcMetadataRegistry::class, $registryDefinition);

        $componentDefinition = new Definition($component);
        $componentDefinition->addTag('twig.component', ['key' => 'test_component']);
        $container->setDefinition('test_component_service', $componentDefinition);

        return $container;
    }
}
