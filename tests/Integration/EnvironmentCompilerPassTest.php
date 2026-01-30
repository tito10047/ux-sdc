<?php

namespace Tito10047\UX\Sdc\Tests\Integration;

use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\AutoDiscovery\AutoDiscoveryComponent;

class EnvironmentCompilerPassTest extends IntegrationTestCase
{
    public function testCompilerPassIsActiveInProd(): void
    {
        // 'test' prostredie sa správa ako 'prod' z pohľadu nášho bundle-u (všetko čo nie je 'dev')
        $kernel = self::bootKernel(['configs' => ['auto_discovery' => true], 'environment' => 'prod']);
        $container = self::getContainer();

        /** @var SdcMetadataRegistry $metadataRegistry */
        $metadataRegistry = $container->get(SdcMetadataRegistry::class);

        // V 'prod' prostredí by mal compiler pass bežať a nájsť komponenty
        $this->assertNotNull($metadataRegistry->getMetadata(AutoDiscoveryComponent::class), 'Compiler pass should run in prod environment');

        $cachePath = $container->getParameter('kernel.cache_dir') . '/sdc_metadata.php';
        $this->assertFileExists($cachePath);
    }

    public function testCompilerPassIsDisabledInDev(): void
    {
        // Najprv spustíme v prod, aby sa vytvorila cache
        $this->testCompilerPassIsActiveInProd();

        // Potom spustíme v dev
        $kernel = self::bootKernel(['configs' => ['auto_discovery' => true], 'environment' => 'dev']);
        $container = self::getContainer();

        /** @var SdcMetadataRegistry $metadataRegistry */
        $metadataRegistry = $container->get(SdcMetadataRegistry::class);

        // V 'dev' prostredí by compiler pass nemal bežať, takže metadata budú prázdne
        $this->assertNull($metadataRegistry->getMetadata('AutoDiscoveryComponent'), 'Compiler pass should NOT run in dev environment');

        $cachePath = $container->getParameter('kernel.cache_dir') . '/sdc_metadata.php';
        $this->assertFileDoesNotExist($cachePath, 'Cache file should be deleted in dev environment');
    }

    public function testSdcComponentIsStillRegisteredAsTwigComponentInDev(): void
    {
        self::bootKernel(['configs' => ['auto_discovery' => true], 'environment' => 'dev']);
        $container = self::getContainer();

        // Overíme, či je komponent stále registrovaný v TwigComponent bundle
        $this->assertTrue($container->has('Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\AutoDiscovery\SdcAutoDiscoveryComponent'));
    }
}
