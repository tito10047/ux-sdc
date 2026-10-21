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
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;
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
    private const FIXTURES = __DIR__ . '/../Service/Fixtures';

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
        $this->addTwigPaths($container, [self::FIXTURES]);
        $this->addAssetMapperPaths($container, [self::FIXTURES => '']);

        (new AssetComponentCompilerPass())->process($container);

        $map = require $this->cachePath;
        $this->assertSame('Alert/Alert.html.twig', $map[Alert::class]['template']);
        $this->assertSame(['vendor/explicit.css', 'Alert/Alert.css', 'Alert/Alert.js'], array_column($map[Alert::class]['assets'], 'path'));
        $this->assertSame([], $container->getCompiler()->getLog());
    }

    /**
     * The template is named after the namespace of its Twig path, the assets
     * after the namespace of their AssetMapper path.
     */
    public function testDumpsNamespacedNames(): void
    {
        $container = $this->createContainer(metadataCache: true, autoDiscovery: true, component: Alert::class);
        $this->addTwigPaths($container, [self::FIXTURES => 'ui']);
        $this->addAssetMapperPaths($container, [self::FIXTURES => 'lib/ui']);

        (new AssetComponentCompilerPass())->process($container);

        $map = require $this->cachePath;
        $this->assertSame('@ui/Alert/Alert.html.twig', $map[Alert::class]['template']);
        $this->assertSame(['vendor/explicit.css', 'lib/ui/Alert/Alert.css', 'lib/ui/Alert/Alert.js'], array_column($map[Alert::class]['assets'], 'path'));
    }

    /**
     * There is no `logger` service while the container is built: what the
     * resolver has to leave out goes to the compiler log, which the kernel keeps
     * for debug containers and the profiler shows.
     */
    public function testFilesOutsideEveryRootAreReportedToTheCompilerLog(): void
    {
        $container = $this->createContainer(metadataCache: true, autoDiscovery: true, component: Alert::class);

        (new AssetComponentCompilerPass())->process($container);

        $map = require $this->cachePath;
        $this->assertSame(['vendor/explicit.css'], array_column($map[Alert::class]['assets'], 'path'));
        $this->assertNull($map[Alert::class]['template']);

        $log = $container->getCompiler()->getLog();
        $this->assertCount(3, $log);
        $this->assertStringStartsWith(AssetComponentCompilerPass::class . ': [warning] Component "' . Alert::class . '": "' . realpath(self::FIXTURES . '/Alert/Alert.css') . '" next to the class is outside every AssetMapper path (none configured)', $log[0]);
        $this->assertStringStartsWith(AssetComponentCompilerPass::class . ': [warning] Component "' . Alert::class . '": the template "' . realpath(self::FIXTURES . '/Alert/Alert.html.twig') . '" next to the class is outside every Twig path (none configured)', $log[2]);
    }

    public function testDumpsNoTemplateForAComponentRegisteredWithOne(): void
    {
        $container = $this->createContainer(metadataCache: true, autoDiscovery: true, component: Alert::class, tag: ['template' => 'alert.html.twig']);
        $this->addTwigPaths($container, [self::FIXTURES]);
        $this->addAssetMapperPaths($container, [self::FIXTURES => '']);

        (new AssetComponentCompilerPass())->process($container);

        $map = require $this->cachePath;
        $this->assertNull($map[Alert::class]['template']);
        $this->assertSame(['vendor/explicit.css', 'Alert/Alert.css', 'Alert/Alert.js'], array_column($map[Alert::class]['assets'], 'path'));
    }

    /**
     * The runtime resolver works from the same roots and registrations as the
     * build-time one, read from the services that use them: Twig paths in
     * their order with the main namespace preferred for a directory registered
     * twice, AssetMapper paths relative to the project directory, components
     * registered with a template or a template method.
     */
    public function testGivesTheRuntimeResolverTheRootsAndExplicitTemplates(): void
    {
        $container = $this->createContainer(metadataCache: false, component: Alert::class, tag: ['template_from_method' => 'template']);
        $container->setParameter('kernel.project_dir', \dirname(__DIR__, 3));
        $container->setParameter('fixtures_dir', self::FIXTURES);
        $container->register(ComponentMetadataResolver::class);
        $container->register('other_component', MockComponent::class)->addTag('twig.component', ['key' => 'other']);

        $this->addTwigPaths($container, ['%fixtures_dir%/' => 'component', '%fixtures_dir%' => null, __DIR__ => 'Unit', '/does/not/exist' => null]);
        $this->addAssetMapperPaths($container, ['tests/Unit/Service/Fixtures' => '', __DIR__ . '/' => 'unit', 'missing/' => '']);

        (new AssetComponentCompilerPass())->process($container);

        $definition = $container->getDefinition(ComponentMetadataResolver::class);
        $this->assertSame([realpath(self::FIXTURES) => null, realpath(__DIR__) => 'Unit'], $definition->getArgument('$twigRoots'));
        $this->assertSame([realpath(self::FIXTURES) => '', realpath(__DIR__) => 'unit'], $definition->getArgument('$assetRoots'));
        $this->assertSame([Alert::class], $definition->getArgument('$classesWithExplicitTemplate'));
    }

    /**
     * Without TwigBundle's loader or with AssetMapper disabled there is
     * nothing to resolve against.
     */
    public function testGivesTheRuntimeResolverNoRootsWithoutTheServices(): void
    {
        $container = $this->createContainer(metadataCache: false);
        $container->register(ComponentMetadataResolver::class);

        (new AssetComponentCompilerPass())->process($container);

        $definition = $container->getDefinition(ComponentMetadataResolver::class);
        $this->assertSame([], $definition->getArgument('$twigRoots'));
        $this->assertSame([], $definition->getArgument('$assetRoots'));
        $this->assertSame([], $definition->getArgument('$classesWithExplicitTemplate'));
    }

    public function testFailsOnAnAssetMapperRepositoryItDoesNotKnow(): void
    {
        $container = $this->createContainer(metadataCache: false);
        $container->register('asset_mapper.repository', \stdClass::class)->setArguments([new Reference('somewhere')]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"asset_mapper.repository"');

        (new AssetComponentCompilerPass())->process($container);
    }

    /**
     * As TwigBundle registers them: addPath($path) for the main namespace.
     *
     * @param array<int|string, string|null> $paths
     */
    private function addTwigPaths(ContainerBuilder $container, array $paths): void
    {
        $loader = $container->register('twig.loader.native_filesystem', \stdClass::class);

        foreach ($paths as $path => $namespace) {
            if (\is_int($path)) {
                [$path, $namespace] = [$namespace, null];
            }

            $loader->addMethodCall('addPath', null === $namespace ? [$path] : [$path, $namespace]);
        }
    }

    /**
     * As FrameworkBundle sets them: the directory => namespace map, first argument.
     *
     * @param array<string, string> $paths
     */
    private function addAssetMapperPaths(ContainerBuilder $container, array $paths): void
    {
        $container->register('asset_mapper.repository', \stdClass::class)->setArguments([$paths, '%kernel.project_dir%']);
    }

    /**
     * @param class-string          $component
     * @param array<string, string> $tag
     */
    private function createContainer(bool $metadataCache, bool $autoDiscovery = false, string $component = MockComponent::class, array $tag = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('ux_sdc.auto_discovery', $autoDiscovery);
        $container->setParameter(AssetComponentCompilerPass::METADATA_CACHE_PARAMETER, $metadataCache);

        $registryDefinition = new Definition(SdcMetadataRegistry::class);
        $registryDefinition->setArgument('$cachePath', $this->cachePath);
        $container->setDefinition(SdcMetadataRegistry::class, $registryDefinition);

        $componentDefinition = new Definition($component);
        $componentDefinition->addTag('twig.component', ['key' => 'test_component', ...$tag]);
        $container->setDefinition('test_component_service', $componentDefinition);

        return $container;
    }
}
