<?php

namespace Tito10047\UX\TwigComponentSdc\Tests\Integration;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\MappedAsset;
use Tito10047\UX\TwigComponentSdc\Cache\AssetMapperCacheWarmer;
use Tito10047\UX\TwigComponentSdc\EventListener\AssetResponseListener;

class CacheOptimizationIntegrationTest extends IntegrationTestCase
{
    public function testCacheIsWarmedAndUsed(): void
    {
        $kernel = self::bootKernel(['configs' => []]);
        $container = self::getContainer();
        $cacheDir = $kernel->getCacheDir();

        $assetMapper = $this->createMock(AssetMapperInterface::class);
        $assetMapper->expects($this->any())
            ->method('getAsset')
            ->willReturnCallback(function ($path) {
                return new MappedAsset($path, publicPath: '/assets/warmed/'.$path);
            });

        // Musíme nahradiť službu v kontajneri skôr ako sa spustí warmer (ak by sa spúšťal automaticky)
        // Alebo ho spustíme manuálne pre test
        $container->set(AssetMapperInterface::class, $assetMapper);

        /** @var AssetMapperCacheWarmer $warmer */
        $warmer = $container->get(AssetMapperCacheWarmer::class);
        $warmer->warmUp($cacheDir);

        $cacheFile = $cacheDir . '/twig_component_sdc_assets.php';
        $this->assertFileExists($cacheFile);

        /** @var AssetResponseListener $listener */
        $listener = $container->get(AssetResponseListener::class);

        // Teraz vyskúšame získať cestu. Mala by prísť z cache.
        // AssetResponseListener v teste má nastavenú cachePath na túto cestu vďaka config/services.php a TestKernelu.
        
        $publicPath = $listener->getPublicPath('css/test.css');
        $this->assertEquals('/assets/warmed/css/test.css', $publicPath);

        // Overíme, že to nie je debug fallback (vypneme debug)
        $reflection = new \ReflectionClass($listener);
        $debugProp = $reflection->getProperty('debug');
        $debugProp->setAccessible(true);
        $debugProp->setValue($listener, false);

        // Stále by to malo fungovať, lebo je to v cache
        $publicPath = $listener->getPublicPath('css/test.css');
        $this->assertEquals('/assets/warmed/css/test.css', $publicPath);

        // Ak vymažeme cache file, malo by to vrátiť null (keďže debug je false)
        unlink($cacheFile);
        $publicPath = $listener->getPublicPath('css/test.css');
        $this->assertNull($publicPath);
    }
}
