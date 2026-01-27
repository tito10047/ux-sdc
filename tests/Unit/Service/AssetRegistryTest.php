<?php

namespace Tito10047\UX\Sdc\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Tito10047\UX\Sdc\Service\AssetRegistry;

final class AssetRegistryTest extends TestCase
{
    public function testAddAndGetSortedAssets(): void
    {
        $registry = new AssetRegistry();
        $registry->addAsset('style.css', 'css', 10);
        $registry->addAsset('script.js', 'js', 20);
        $registry->addAsset('low-priority.css', 'css', 5);

        $assets = $registry->getSortedAssets();

        $this->assertCount(3, $assets);
        $this->assertSame('script.js', $assets[0]['path']);
        $this->assertSame('style.css', $assets[1]['path']);
        $this->assertSame('low-priority.css', $assets[2]['path']);
    }

    public function testUniqueAssets(): void
    {
        $registry = new AssetRegistry();
        $registry->addAsset('style.css', 'css', 10);
        $registry->addAsset('style.css', 'css', 20); // Vyššia priorita

        $assets = $registry->getSortedAssets();

        $this->assertCount(1, $assets);
        $this->assertSame(20, $assets[0]['priority']);
    }
}
