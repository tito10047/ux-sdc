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

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tito10047\UX\Sdc\CompilerPass\AssetComponentCompilerPass;
use Tito10047\UX\Sdc\DependencyInjection\SdcExtension;
use Tito10047\UX\Sdc\SdcBundle;

class SdcBundleTest extends TestCase
{
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
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', '/var/www');
        $extension = new SdcExtension();

        $container->prependExtensionConfig('ux_sdc', [
            'ux_components_dir' => '/var/www/src_component',
            'component_namespace' => 'App\\Component\\'
        ]);

        $extension->prepend($container);

        $twigConfigs = $container->getExtensionConfig('twig');
        $this->assertNotEmpty($twigConfigs);
        $found = false;
        foreach ($twigConfigs as $tConfig) {
            if (isset($tConfig['paths']) && array_key_exists('/var/www/src_component', $tConfig['paths'])) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Twig path /var/www/src_component not found');

        $assetMapperConfigs = $container->getExtensionConfig('framework');
        $this->assertNotEmpty($assetMapperConfigs);
        $this->assertContains('/var/www/src_component', $assetMapperConfigs[0]['asset_mapper']['paths']);

        $twigComponentConfigs = $container->getExtensionConfig('twig_component');
        $this->assertNotEmpty($twigComponentConfigs);
        $this->assertEquals('', $twigComponentConfigs[0]['defaults']['App\\Component\\']['template_directory']);
    }
}
