<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\Response;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tito10047\UX\Sdc\Response\ResolvedAsset;

final class ResolvedAssetTest extends TestCase
{
    public function testAStylesheetIsTrackedByTurboAndCarriesTheNonce(): void
    {
        $asset = new ResolvedAsset('/a.css', 'css');

        $this->assertTrue($asset->isStylesheet());
        $this->assertFalse($asset->isModule());
        $this->assertSame(['nonce' => 'n0nce', 'data-turbo-track' => 'dynamic'], $asset->tagAttributes('n0nce'));
    }

    public function testAScriptIsAModuleByDefault(): void
    {
        $asset = new ResolvedAsset('/a.js', 'js');

        $this->assertFalse($asset->isStylesheet());
        $this->assertTrue($asset->isModule());
        $this->assertSame(['type' => 'module', 'nonce' => null], $asset->tagAttributes(null));
    }

    public function testAFontIsPreloadedInCorsModeWithItsMimeType(): void
    {
        $asset = new ResolvedAsset('/assets/fonts/inter-1a2b.woff2', 'font');

        $this->assertTrue($asset->isPreload());
        $this->assertFalse($asset->isStylesheet());
        $this->assertFalse($asset->isScript());
        $this->assertFalse($asset->isModule());
        $this->assertSame(['as' => 'font', 'type' => 'font/woff2', 'crossorigin' => true], $asset->tagAttributes('n0nce'));
    }

    public function testAnImageIsPreloadedWithoutCors(): void
    {
        $asset = new ResolvedAsset('/assets/hero-1a2b.avif', 'image', ['fetchpriority' => 'high']);

        $this->assertTrue($asset->isPreload());
        $this->assertSame(['as' => 'image', 'type' => 'image/avif', 'crossorigin' => null, 'fetchpriority' => 'high'], $asset->tagAttributes(null));
    }

    /**
     * A URL without an extension has no MIME type to tell; the asset may set
     * `type` and `crossorigin` itself, or leave them out.
     */
    public function testThePreloadAttributesOfTheAssetWin(): void
    {
        $asset = new ResolvedAsset('https://cdn.example.com/font', 'font', ['type' => 'font/woff2', 'crossorigin' => 'use-credentials']);
        $this->assertSame(['as' => 'font', 'type' => 'font/woff2', 'crossorigin' => 'use-credentials'], $asset->tagAttributes(null));

        $asset = new ResolvedAsset('/assets/logo.svg', 'image', ['type' => false]);
        $this->assertSame(['as' => 'image', 'type' => false, 'crossorigin' => null], $asset->tagAttributes(null));
    }

    /**
     * The defaults keep their place, so the tag renders the same attributes in
     * the same order whatever the asset overrides.
     */
    public function testTheAttributesOfTheAssetOverrideTheDefaultsInPlace(): void
    {
        $asset = new ResolvedAsset('/a.css', 'css', ['media' => 'print', 'data-turbo-track' => false, 'nonce' => 'own']);

        $this->assertSame(['nonce' => 'own', 'data-turbo-track' => false, 'media' => 'print'], $asset->tagAttributes('page'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function provideScriptTypes(): iterable
    {
        yield 'no type' => [[], true];
        yield 'module' => [['type' => 'module'], true];
        yield 'module, any case and spacing' => [['type' => ' MODULE '], true];
        yield 'type left out' => [['type' => false], false];
        yield 'type null' => [['type' => null], false];
        yield 'classic type' => [['type' => 'text/javascript'], false];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('provideScriptTypes')]
    public function testTheTypeAttributeTellsAModule(array $attributes, bool $module): void
    {
        $this->assertSame($module, (new ResolvedAsset('/a.js', 'js', $attributes))->isModule());
    }

    public function testTheDeclaredPathIsThePathUnlessGiven(): void
    {
        $this->assertSame('/build/a.css', (new ResolvedAsset('/build/a.css', 'css'))->declaredPath);
        $this->assertSame('Alert/Alert.css', (new ResolvedAsset('/assets/Alert/Alert-1a2b.css', 'css', [], 'Alert/Alert.css'))->declaredPath);
    }
}
