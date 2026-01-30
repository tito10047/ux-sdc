<?php

namespace Tito10047\UX\Sdc\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\ComponentFactory;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\SdcComponent;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\SdcComponentWithAsset;

class AsSdcComponentIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testSdcComponentIsRegistered(): void
    {
        $kernel = new TestKernel([]);

        $kernel->boot();
        $container = $kernel->getContainer();

        /** @var ComponentFactory $componentFactory */
        $componentFactory = $container->get('ux.twig_component.component_factory');

        // This will fail unless we register SdcComponent in TestKernel
        $metadata = $componentFactory->metadataFor('SdcComponent');

        $this->assertEquals('SdcComponent', $metadata->getName());
        $this->assertEquals(SdcComponent::class, $metadata->getClass());
    }

    public function testSdcComponentAssetsAreCollected(): void
    {
        $kernel = new TestKernel([]);
        $kernel->boot();
        $container = $kernel->getContainer();

        /** @var SdcMetadataRegistry $metadataRegistry */
        $metadataRegistry = $container->get(SdcMetadataRegistry::class);
        $assets = $metadataRegistry->getMetadata(SdcComponent::class);

        $this->assertCount(2, $assets);

        $this->assertEquals('css/sdc.css', $assets[0]['path']);
        $this->assertEquals('css', $assets[0]['type']);

        $this->assertEquals('js/sdc.js', $assets[1]['path']);
        $this->assertEquals('js', $assets[1]['type']);
    }

    public function testSdcComponentWithAssetAttribute(): void
    {
        $kernel = new TestKernel([]);
        $kernel->boot();
        $container = $kernel->getContainer();

        /** @var SdcMetadataRegistry $metadataRegistry */
        $metadataRegistry = $container->get(SdcMetadataRegistry::class);
        $assets = $metadataRegistry->getMetadata(SdcComponentWithAsset::class);

        $this->assertCount(3, $assets);

        // From #[Asset]
        $this->assertEquals('css/extra.css', $assets[0]['path']);

        // From #[AsSdcComponent]
        $this->assertEquals('css/sdc.css', $assets[1]['path']);
        $this->assertEquals('js/sdc.js', $assets[2]['path']);
    }
}
