<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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

    public function testResetDropsAssetsOfThePreviousRequest(): void
    {
        $registry = new AssetRegistry();
        $registry->addAsset('page-a.css', 'css');
        $registry->addAsset('page-a.js', 'js');

        $registry->reset();

        $registry->addAsset('page-b.css', 'css');

        $this->assertSame(['page-b.css'], array_column($registry->getSortedAssets(), 'path'));
    }

    public function testClearDropsAssetsOfThePreviousBatch(): void
    {
        $registry = new AssetRegistry();
        $registry->addAsset('first.css', 'css', 10);

        $registry->clear();

        $registry->addAsset('second.css', 'css');

        $this->assertSame(['second.css'], array_column($registry->getSortedAssets(), 'path'));
    }

    public function testResetForgetsThePriorityOfAPreviousRequest(): void
    {
        $registry = new AssetRegistry();
        $registry->addAsset('style.css', 'css', 50);

        $registry->reset();

        $registry->addAsset('style.css', 'css', 5);

        $this->assertSame(5, $registry->getSortedAssets()[0]['priority']);
    }
}
