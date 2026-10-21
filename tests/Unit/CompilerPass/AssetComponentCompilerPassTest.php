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
use Symfony\Component\DependencyInjection\Argument\AbstractArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;
use Tito10047\UX\Sdc\Attribute\Asset;
use Tito10047\UX\Sdc\CompilerPass\AssetComponentCompilerPass;
use Tito10047\UX\Sdc\Debug\ComponentInspector;
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

        $this->assertArrayHasKey(MockComponent::class, $map['components']);
        $this->assertSame([], $map['anonymous']);
        $this->assertSame('test.css', $map['components'][MockComponent::class]['assets'][0]['path']);
        $this->assertSame(5, $map['components'][MockComponent::class]['assets'][0]['priority']);
        $this->assertNull($map['components'][MockComponent::class]['template']);
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
        $this->assertSame('Alert/Alert.html.twig', $map['components'][Alert::class]['template']);
        $this->assertSame(['vendor/explicit.css', 'Alert/Alert.css', 'Alert/Alert.js'], array_column($map['components'][Alert::class]['assets'], 'path'));
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
        $this->assertSame('@ui/Alert/Alert.html.twig', $map['components'][Alert::class]['template']);
        $this->assertSame(['vendor/explicit.css', 'lib/ui/Alert/Alert.css', 'lib/ui/Alert/Alert.js'], array_column($map['components'][Alert::class]['assets'], 'path'));
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
        $this->assertSame(['vendor/explicit.css'], array_column($map['components'][Alert::class]['assets'], 'path'));
        $this->assertNull($map['components'][Alert::class]['template']);

        // Kept for debug:sdc, which reads them from the registry.
        $this->assertSame(
            [realpath(self::FIXTURES . '/Alert/Alert.css'), realpath(self::FIXTURES . '/Alert/Alert.js'), realpath(self::FIXTURES . '/Alert/Alert.html.twig')],
            array_map(static fn (array $problem): string => $problem['context']['file'], $map['components'][Alert::class]['problems']),
        );

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
        $this->assertNull($map['components'][Alert::class]['template']);
        $this->assertSame(['vendor/explicit.css', 'Alert/Alert.css', 'Alert/Alert.js'], array_column($map['components'][Alert::class]['assets'], 'path'));
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

    /**
     * debug:sdc lists the classes of the `twig.component` services that exist,
     * once each, and knows which have an explicit template.
     */
    public function testGivesTheInspectorTheComponents(): void
    {
        $container = $this->createContainer(metadataCache: false, component: Alert::class, tag: ['template' => 'alert.html.twig']);
        $container->register(ComponentInspector::class);
        $container->register('other_component', MockComponent::class)
            ->addTag('twig.component', ['key' => 'other'])
            ->addTag('twig.component', ['key' => 'other_alias']);
        $container->register('missing_component', 'App\\DoesNotExist')->addTag('twig.component', ['key' => 'missing']);

        (new AssetComponentCompilerPass())->process($container);

        $definition = $container->getDefinition(ComponentInspector::class);
        $this->assertSame([Alert::class, MockComponent::class], $definition->getArgument('$componentClasses'));
        $this->assertSame([Alert::class], $definition->getArgument('$classesWithExplicitTemplate'));
    }

    /**
     * The templates of anonymous components, under the name Twig Components
     * gives each and the component names it may know them by: in the
     * anonymous template directory of each main Twig path, in `components/`
     * of each namespaced one, and in ux_components_dir. The templates of the
     * component classes are left out — the one next to a class, and the one
     * Twig Components gives a class.
     */
    public function testGivesTheInspectorTheAnonymousTemplates(): void
    {
        $container = $this->createContainer(metadataCache: false, component: Alert::class);
        $container->register(ComponentInspector::class);
        $this->addTwigPaths($container, [self::FIXTURES, self::FIXTURES . '/Acme' => 'Acme']);
        $this->addComponentFactory($container, ['default' => ['key' => 'default', 'class' => MockComponent::class, 'template' => 'components/Default.html.twig']]);
        $container->register('ux.twig_component.component_template_finder', \stdClass::class)->setArguments([new Reference('twig.loader'), 'components/']);

        (new AssetComponentCompilerPass())->process($container);

        $this->assertSame([
            'components/Notice.html.twig' => ['Notice'],
            'components/Panel/index.html.twig' => ['Panel', 'Panel:index'],
            '@Acme/components/Button.html.twig' => ['Acme:Button'],
        ], $container->getDefinition(ComponentInspector::class)->getArgument('$anonymousTemplates'));
    }

    /**
     * ux_components_dir, a Twig path of the main namespace, holds anonymous
     * components under their path, where ComponentDirectoryTemplateFinder
     * finds them. Without `twig_component.anonymous_template_directory`
     * (deprecated in Twig Components 2.x), `components/` is looked in.
     */
    public function testAnonymousTemplatesOfTheComponentDirectory(): void
    {
        $container = $this->createContainer(metadataCache: true, autoDiscovery: true, component: Alert::class);
        $container->register(ComponentInspector::class);
        $container->setParameter('ux_sdc.ux_components_dir', self::FIXTURES);
        $this->addTwigPaths($container, [self::FIXTURES]);
        $this->addAssetMapperPaths($container, [self::FIXTURES => '']);
        $container->register('ux.twig_component.component_template_finder', \stdClass::class)->setArguments([new Reference('twig.loader'), null]);

        (new AssetComponentCompilerPass())->process($container);

        $this->assertSame([
            'components/Default.html.twig' => ['Default'],
            'components/Notice.html.twig' => ['Notice'],
            'components/Panel/index.html.twig' => ['Panel', 'Panel:index'],
            'Acme/components/Button.html.twig' => ['Acme:components:Button'],
        ], $container->getDefinition(ComponentInspector::class)->getArgument('$anonymousTemplates'), 'The template next to Alert belongs to the class.');

        $map = require $this->cachePath;
        $this->assertSame(['components/Notice.html.twig', 'Acme/components/Button.html.twig'], array_keys($map['anonymous']));
        $this->assertSame(['components/Notice.css', 'components/Notice.js'], array_column($map['anonymous']['components/Notice.html.twig']['assets'], 'path'));
        $this->assertSame([], $map['anonymous']['components/Notice.html.twig']['problems']);
        $this->assertSame('Alert/Alert.html.twig', $map['components'][Alert::class]['template']);
    }

    /**
     * Twig Components joins its anonymous template directory and the name
     * with a slash, so an empty directory gives names with a leading one.
     */
    public function testAnEmptyAnonymousTemplateDirectoryIsEveryMainTwigPath(): void
    {
        $container = $this->createContainer(metadataCache: false, component: Alert::class);
        $container->register(ComponentInspector::class);
        $this->addTwigPaths($container, [self::FIXTURES . '/components']);
        $container->register('ux.twig_component.component_template_finder', \stdClass::class)->setArguments([new Reference('twig.loader'), '']);

        (new AssetComponentCompilerPass())->process($container);

        $this->assertSame([
            '/Default.html.twig' => ['Default'],
            '/Notice.html.twig' => ['Notice'],
            '/Panel/index.html.twig' => ['Panel', 'Panel:index'],
        ], $container->getDefinition(ComponentInspector::class)->getArgument('$anonymousTemplates'));
    }

    /**
     * The templates of the component classes come from the configuration
     * TwigComponentPass gives the component factory: without it, the pass ran
     * too early, or Twig Components changed.
     */
    public function testFailsOnAComponentFactoryWithoutComponentConfiguration(): void
    {
        $container = $this->createContainer(metadataCache: false);
        $container->register('ux.twig_component.component_factory', \stdClass::class)
            ->setArguments([null, null, null, null, new AbstractArgument('Added in TwigComponentPass.')]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"ux.twig_component.component_factory"');

        (new AssetComponentCompilerPass())->process($container);
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
     * As TwigComponentPass sets it: the configuration of each component, fifth argument.
     *
     * @param array<string, array<string, string>> $config
     */
    private function addComponentFactory(ContainerBuilder $container, array $config): void
    {
        $container->register('ux.twig_component.component_factory', \stdClass::class)->setArguments([null, null, null, null, $config]);
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
