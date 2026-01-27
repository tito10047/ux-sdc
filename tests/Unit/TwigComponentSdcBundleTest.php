<?php

namespace Tito10047\UX\Sdc\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tito10047\UX\Sdc\SdcBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use Tito10047\UX\Sdc\DependencyInjection\SdcExtension;

class SdcBundleTest extends TestCase
{
    public function testLoadExtensionSetsParameters(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', realpath(__DIR__ . '/../..'));
        $extension = new SdcExtension();

        $config = [
            'auto_discovery' => true,
            'ux_components_dir' => '%kernel.project_dir%/tests/Visual/Generated',
            'component_namespace' => 'Tito10047\\UX\\Sdc\\Tests\\Visual\\Generated',
            'placeholder' => '<!-- __UX_TWIG_COMPONENT_ASSETS__ -->',
            'stimulus' => ['enabled' => true],
        ];

        $extension->load([$config], $container);

        $this->assertTrue($container->hasParameter('sdc.auto_discovery'));
        $this->assertEquals('%kernel.project_dir%/tests/Visual/Generated', $container->getParameter('sdc.ux_components_dir'));
        $this->assertEquals('Tito10047\\UX\\Sdc\\Tests\\Visual\\Generated\\', $container->getParameter('sdc.component_namespace'));
    }

    public function testPrependAddsConfiguration(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', '/var/www');
        $extension = new SdcExtension();

        $container->prependExtensionConfig('sdc', [
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
