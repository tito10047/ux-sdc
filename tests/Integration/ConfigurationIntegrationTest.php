<?php

namespace Tito10047\UX\TwigComponentSdc\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\ComponentFactory;
use Tito10047\UX\TwigComponentSdc\Tests\Integration\Fixtures\Component\TestComponent;

class ConfigurationIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testDefaultConfigurationIsApplied(): void
    {
        $kernel = new TestKernel([]);
        $kernel->boot();
        $container = $kernel->getContainer();

        $this->assertEquals(
            realpath($kernel->getProjectDir() . '/tests/Integration/Fixtures/Component'),
            realpath($container->getParameter('twig_component_sdc.ux_components_dir'))
        );
        $this->assertEquals('Tito10047\UX\TwigComponentSdc\Tests\Integration\Fixtures\Component\\', $container->getParameter('twig_component_sdc.component_namespace'));
    }

    public function testCustomConfigurationIsApplied(): void
    {
        $kernel = new TestKernel([
            'ux_components_dir' => '%kernel.project_dir%/custom_dir',
            'component_namespace' => 'Tito10047\\UX\TwigComponentSdc\\Tests\\Integration\\Fixtures\\Component\\',
        ]);
        $kernel->boot();
        $container = $kernel->getContainer();

        $this->assertEquals($kernel->getProjectDir() . '/custom_dir', $container->getParameter('twig_component_sdc.ux_components_dir'));
        $this->assertEquals('Tito10047\\UX\TwigComponentSdc\\Tests\\Integration\\Fixtures\\Component\\', $container->getParameter('twig_component_sdc.component_namespace'));
    }

    public function testComponentIsLoadedFromCustomNamespace(): void
    {
        $kernel = new TestKernel([
            'ux_components_dir' => '%kernel.project_dir%/tests/Integration/Fixtures/Component',
            'component_namespace' => 'Tito10047\\UX\TwigComponentSdc\\Tests\\Integration\\Fixtures\\Component\\',
        ]);
        $kernel->boot();

        $container = $kernel->getContainer();

        /** @var ComponentFactory $componentFactory */
        $componentFactory = $container->get('ux.twig_component.component_factory');

        $metadata = $componentFactory->metadataFor('TestComponent');

        $this->assertEquals('TestComponent', $metadata->getName());
        $this->assertEquals(TestComponent::class, $metadata->getClass());
    }
}
