<?php

namespace Tito10047\UX\TwigComponentSdc\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\MappedAsset;
use Tito10047\UX\TwigComponentSdc\Cache\AssetMapperCacheWarmer;

class AssetMapperCacheWarmerTest extends TestCase
{
    public function testWarmUp(): void
    {
        $cacheDir = sys_get_temp_dir() . '/sdc_cache_' . uniqid();
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }

        $assetMapper = $this->createMock(AssetMapperInterface::class);
        
        $asset1 = new MappedAsset('styles/app.css', publicPath: '/assets/styles/app.12345.css');
        $asset2 = new MappedAsset('js/app.js', publicPath: '/assets/js/app.67890.js');

        $assetMapper->expects($this->exactly(2))
            ->method('getAsset')
            ->willReturnMap([
                ['styles/app.css', $asset1],
                ['js/app.js', $asset2],
            ]);
            
        // Simulujeme zoznam assetov, ktoré chceme zakesovať
        // Budeme potrebovať spôsob ako odovzdať zoznam logických ciest do warmera
        $logicalPaths = ['styles/app.css', 'js/app.js'];

        $warmer = new AssetMapperCacheWarmer($assetMapper, $logicalPaths);
        $warmer->warmUp($cacheDir);

        $cacheFile = $cacheDir . '/twig_component_sdc_assets.php';
        $this->assertFileExists($cacheFile);

        $map = require $cacheFile;
        $this->assertEquals([
            'styles/app.css' => '/assets/styles/app.12345.css',
            'js/app.js' => '/assets/js/app.67890.js',
        ], $map);

        // Cleanup
        unlink($cacheFile);
        rmdir($cacheDir);
    }
}
