<?php

namespace Tito10047\UX\TwigComponentSdc\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tito10047\UX\TwigComponentSdc\TwigComponentSdcBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

class TwigComponentSdcBundleTest extends TestCase
{
    public function testLoadExtensionSetsParameters(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', realpath(__DIR__ . '/../..'));
        $bundle = new TwigComponentSdcBundle();

        $config = [
            'auto_discovery' => true,
            'ux_components_dir' => '%kernel.project_dir%/tests',
            'component_namespace' => 'Tito10047\\UX\\TwigComponentSdc\\Tests',
            'placeholder' => '<!-- __UX_TWIG_COMPONENT_ASSETS__ -->',
            'stimulus' => ['enabled' => true],
        ];

        // Create a real ContainerConfigurator but mock its loader/builder if necessary
        // or just pass a dummy if the bundle doesn't use it for critical things in test
        $loader = $this->createMock(\Symfony\Component\DependencyInjection\Loader\PhpFileLoader::class);
        $instance = new \ReflectionClass(ContainerConfigurator::class);
        $configurator = $instance->newInstanceWithoutConstructor();
        $prop = $instance->getProperty('container');
        $prop->setAccessible(true);
        $prop->setValue($configurator, $container);
        $prop = $instance->getProperty('loader');
        $prop->setAccessible(true);
        $prop->setValue($configurator, $loader);
        $prop = $instance->getProperty('path');
        $prop->setAccessible(true);
        $prop->setValue($configurator, __FILE__);
        $prop = $instance->getProperty('file');
        $prop->setAccessible(true);
        $prop->setValue($configurator, __FILE__);

        $bundle->loadExtension($config, $configurator, $container);

        $this->assertTrue($container->hasParameter('twig_component_sdc.auto_discovery'));
        $this->assertEquals('%kernel.project_dir%/tests', $container->getParameter('twig_component_sdc.ux_components_dir'));
        $this->assertEquals('Tito10047\\UX\\TwigComponentSdc\\Tests\\', $container->getParameter('twig_component_sdc.component_namespace'));
    }

    public function testPrependAddsConfiguration(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', '/var/www');
        $bundle = new TwigComponentSdcBundle();

        $container->prependExtensionConfig('twig_component_sdc', [
            'ux_components_dir' => '/var/www/src_component',
            'component_namespace' => 'App\\Component\\'
        ]);

        $bundle->prepend($container);

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
        $this->assertEquals('/var/www/src_component', $twigComponentConfigs[0]['defaults']['App\\Component\\']['directory']);
        $this->assertEquals('', $twigComponentConfigs[0]['defaults']['App\\Component\\']['template_directory']);
    }
}
