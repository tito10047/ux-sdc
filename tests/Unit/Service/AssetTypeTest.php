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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tito10047\UX\Sdc\Service\AssetType;

final class AssetTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideTypes(): iterable
    {
        yield 'declared' => ['js', 'Alert/Alert.css', 'js'];
        yield 'declared, not loaded' => ['font', 'font.woff2', 'font'];
        yield 'stylesheet by its path' => ['', 'Alert/Alert.css', 'css'];
        yield 'script by its path' => ['', 'Alert/Alert.js', 'js'];
        yield 'anything else is a script' => ['', 'https://cdn.example.com/lib', 'js'];
    }

    #[DataProvider('provideTypes')]
    public function testAnAssetDeclaringNoTypeGetsTheOneOfItsPath(string $declared, string $path, string $type): void
    {
        $this->assertSame($type, AssetType::resolve($declared, $path));
    }

    public function testOnlyStylesheetsAndScriptsAreLoaded(): void
    {
        $this->assertTrue(AssetType::isLoaded('css'));
        $this->assertTrue(AssetType::isLoaded('js'));
        $this->assertFalse(AssetType::isLoaded('font'));
        $this->assertFalse(AssetType::isLoaded(''));
    }
}
