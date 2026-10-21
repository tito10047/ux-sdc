<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Tito10047\UX\Sdc\CompilerPass\AssetComponentCompilerPass;
use Tito10047\UX\Sdc\DependencyInjection\SdcExtension;
use Tito10047\UX\Sdc\SdcBundle;

class SdcBundleTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    public function testContainerExtensionKeepsTheUxSdcAlias(): void
    {
        $extension = (new SdcBundle())->getContainerExtension();

        $this->assertInstanceOf(SdcExtension::class, $extension);
        $this->assertSame('ux_sdc', $extension->getAlias());
    }

    public function testPathIsTheBundleRoot(): void
    {
        $path = (new SdcBundle())->getPath();

        $this->assertSame(\dirname(__DIR__, 2), $path);
        $this->assertDirectoryExists($path . '/templates');
        $this->assertDirectoryExists($path . '/config');
    }

    public function testBuildRegistersTheAssetCompilerPass(): void
    {
        $container = new ContainerBuilder();

        (new SdcBundle())->build($container);

        $passes = array_filter(
            $container->getCompilerPassConfig()->getPasses(),
            static fn (object $pass): bool => $pass instanceof AssetComponentCompilerPass,
        );
        $this->assertCount(1, $passes);
    }

    public function testLoadExtensionSetsParameters(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', realpath(__DIR__ . '/../..'));
        $extension = new SdcExtension();

        $config = [
            'auto_discovery' => true,
            'ux_components_dir' => '%kernel.project_dir%/tests/Integration/Fixtures/Component',
            'component_namespace' => 'Tito10047\\UX\\Sdc\\Tests\\Integration\\Fixtures\\Component',
            'placeholder' => '<!-- __UX_TWIG_COMPONENT_ASSETS__ -->',
            'stimulus' => ['enabled' => true],
        ];

        $extension->load([$config], $container);

        $this->assertTrue($container->hasParameter('ux_sdc.auto_discovery'));
        $this->assertEquals('%kernel.project_dir%/tests/Integration/Fixtures/Component', $container->getParameter('ux_sdc.ux_components_dir'));
        $this->assertEquals('Tito10047\\UX\\Sdc\\Tests\\Integration\\Fixtures\\Component\\', $container->getParameter('ux_sdc.component_namespace'));
    }

    public function testPrependAddsConfiguration(): void
    {
        $componentsDir = $this->temporaryProjectDir().'/src_component';
        (new Filesystem())->mkdir($componentsDir);

        $container = $this->prependedContainer(\dirname($componentsDir), $componentsDir);

        $this->assertSame([$componentsDir => null, $componentsDir.'/' => 'component'], $this->twigPaths($container));

        $assetMapper = $container->getExtensionConfig('framework')[0]['asset_mapper'];
        $this->assertContains($componentsDir, $assetMapper['paths']);
        $this->assertSame([realpath($componentsDir).'/*.twig'], $assetMapper['excluded_patterns']);

        $twigComponentConfigs = $container->getExtensionConfig('twig_component');
        $this->assertNotEmpty($twigComponentConfigs);
        $this->assertEquals('', $twigComponentConfigs[0]['defaults']['App\\Component\\']['template_directory']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingComponentsDirProvider(): iterable
    {
        yield 'absolute' => ['%kernel.project_dir%/src_component'];
        yield 'relative to the project directory' => ['src_component'];
    }

    /**
     * Twig's FilesystemLoader, and AssetMapper in debug, throw on a directory
     * that does not exist — as the component directory does not, right after
     * the bundle is installed.
     */
    #[DataProvider('missingComponentsDirProvider')]
    public function testPrependLeavesOutAComponentsDirThatDoesNotExist(string $componentsDir): void
    {
        $projectDir = $this->temporaryProjectDir();

        $container = $this->prependedContainer($projectDir, $componentsDir);

        $this->assertSame([], $this->twigPaths($container));

        $assetMapper = $container->getExtensionConfig('framework')[0]['asset_mapper'];
        $this->assertNotContains($componentsDir, $assetMapper['paths']);
        $this->assertArrayNotHasKey('excluded_patterns', $assetMapper);

        $this->assertContainsEquals(new FileExistenceResource($projectDir.'/src_component'), $container->getResources());

        $twigComponentConfigs = $container->getExtensionConfig('twig_component');
        $this->assertEquals('', $twigComponentConfigs[0]['defaults']['App\\Component\\']['template_directory']);
    }

    public function testPrependRegistersAComponentsDirRelativeToTheProjectDirectory(): void
    {
        $projectDir = $this->temporaryProjectDir();
        (new Filesystem())->mkdir($projectDir.'/src_component');

        $container = $this->prependedContainer($projectDir, 'src_component');

        $this->assertSame(['src_component' => null, 'src_component/' => 'component'], $this->twigPaths($container));
        $this->assertContains('src_component', $container->getExtensionConfig('framework')[0]['asset_mapper']['paths']);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->temporaryPaths);
    }

    private function prependedContainer(string $projectDir, string $componentsDir): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $projectDir);

        $container->prependExtensionConfig('ux_sdc', [
            'ux_components_dir' => $componentsDir,
            'component_namespace' => 'App\\Component\\',
        ]);

        (new SdcExtension())->prepend($container);

        return $container;
    }

    /**
     * @return array<string, string|null>
     */
    private function twigPaths(ContainerBuilder $container): array
    {
        $paths = [];
        foreach ($container->getExtensionConfig('twig') as $config) {
            $paths += $config['paths'] ?? [];
        }

        return $paths;
    }

    private function temporaryProjectDir(): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('ux-sdc-', true);
        (new Filesystem())->mkdir($path);
        $this->temporaryPaths[] = $path;

        return $path;
    }
}
