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
     * @return iterable<string, array{string, string|null}>
     */
    public static function providePaths(): iterable
    {
        yield 'stylesheet' => ['Alert/Alert.css', 'css'];
        yield 'script' => ['Alert/Alert.js', 'js'];
        yield 'module script' => ['Alert/Alert.mjs', 'js'];
        yield 'woff2 font' => ['fonts/inter.woff2', 'font'];
        yield 'woff font' => ['fonts/inter.woff', 'font'];
        yield 'ttf font' => ['fonts/inter.ttf', 'font'];
        yield 'otf font' => ['fonts/inter.otf', 'font'];
        yield 'avif image' => ['images/hero.avif', 'image'];
        yield 'webp image' => ['images/hero.webp', 'image'];
        yield 'png image' => ['images/hero.png', 'image'];
        yield 'jpg image' => ['images/hero.jpg', 'image'];
        yield 'jpeg image' => ['images/hero.jpeg', 'image'];
        yield 'gif image' => ['images/hero.gif', 'image'];
        yield 'svg image' => ['images/logo.svg', 'image'];
        yield 'any case' => ['fonts/Inter.WOFF2', 'font'];
        yield 'URL with a query' => ['https://cdn.example.com/inter.woff2?v=3', 'font'];
        yield 'URL with a fragment' => ['https://cdn.example.com/lib.js#main', 'js'];
        yield 'dot in a directory' => ['https://cdn.example.com/v1.2/lib', null];
        yield 'URL without an extension' => ['https://fonts.googleapis.com/css2?family=Inter', null];
        yield 'host only' => ['https://cdn.example.com', null];
        yield 'other extension' => ['videos/intro.mp4', null];
        yield 'no extension' => ['Alert/Alert', null];
    }

    #[DataProvider('providePaths')]
    public function testTheExtensionOfThePathTellsTheType(string $path, ?string $type): void
    {
        $this->assertSame($type, AssetType::fromPath($path));
    }

    public function testFontsAndImagesAreOnlyPreloaded(): void
    {
        $this->assertTrue(AssetType::isPreloadOnly('font'));
        $this->assertTrue(AssetType::isPreloadOnly('image'));
        $this->assertFalse(AssetType::isPreloadOnly('css'));
        $this->assertFalse(AssetType::isPreloadOnly('js'));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function provideMimeTypes(): iterable
    {
        yield 'woff2' => ['/assets/fonts/inter-1a2b.woff2', 'font/woff2'];
        yield 'woff' => ['/assets/fonts/inter-1a2b.woff', 'font/woff'];
        yield 'ttf' => ['/assets/fonts/inter-1a2b.ttf', 'font/ttf'];
        yield 'otf' => ['/assets/fonts/inter-1a2b.otf', 'font/otf'];
        yield 'avif' => ['/assets/hero.avif', 'image/avif'];
        yield 'webp' => ['/assets/hero.webp', 'image/webp'];
        yield 'png' => ['/assets/hero.png', 'image/png'];
        yield 'jpg' => ['/assets/hero.jpg', 'image/jpeg'];
        yield 'jpeg' => ['/assets/hero.JPEG', 'image/jpeg'];
        yield 'gif' => ['/assets/hero.gif', 'image/gif'];
        yield 'svg' => ['https://cdn.example.com/logo.svg?v=1', 'image/svg+xml'];
        yield 'unknown' => ['https://cdn.example.com/font', null];
    }

    #[DataProvider('provideMimeTypes')]
    public function testTheMimeTypeOfAPreloadComesFromTheExtension(string $path, ?string $mimeType): void
    {
        $this->assertSame($mimeType, AssetType::mimeType($path));
    }
}
