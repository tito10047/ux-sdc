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

use PHPUnit\Framework\TestCase;
use Tito10047\UX\Sdc\Response\AssetTagRenderer;
use Tito10047\UX\Sdc\Response\ResolvedAsset;

final class AssetTagRendererTest extends TestCase
{
    public function testRendersAStylesheetLink(): void
    {
        $this->assertSame(
            '<link rel="stylesheet" href="/assets/a.css" nonce="n0nce" data-turbo-track="dynamic">',
            (new AssetTagRenderer())->render(new ResolvedAsset('/assets/a.css', 'css'), 'n0nce'),
        );
    }

    public function testRendersAModuleScript(): void
    {
        $this->assertSame(
            '<script src="/assets/a.js" type="module"></script>',
            (new AssetTagRenderer())->render(new ResolvedAsset('/assets/a.js', 'js'), null),
        );
    }

    public function testRendersAPreloadLinkForAFont(): void
    {
        $this->assertSame(
            '<link rel="preload" href="/assets/inter.woff2" as="font" type="font/woff2" crossorigin>',
            (new AssetTagRenderer())->render(new ResolvedAsset('/assets/inter.woff2', 'font'), 'n0nce'),
        );
    }

    public function testRendersAPreloadLinkForAnImage(): void
    {
        $this->assertSame(
            '<link rel="preload" href="/assets/hero.webp" as="image" type="image/webp" fetchpriority="high">',
            (new AssetTagRenderer())->render(new ResolvedAsset('/assets/hero.webp', 'image', ['fetchpriority' => 'high']), null),
        );
    }

    public function testRendersBooleanAttributesLikeTwigComponents(): void
    {
        $asset = new ResolvedAsset('/a.js', 'js', ['type' => false, 'defer' => true, 'async' => false, 'nonce' => null]);

        $this->assertSame('<script src="/a.js" defer></script>', (new AssetTagRenderer())->render($asset, 'n0nce'));
    }

    public function testEscapesThePathAndTheAttributeValues(): void
    {
        $asset = new ResolvedAsset('/a.css?x="1"&y=2', 'css', ['data-id' => '"><script>']);

        $this->assertSame(
            '<link rel="stylesheet" href="/a.css?x=&quot;1&quot;&amp;y=2" nonce="&quot;" data-turbo-track="dynamic" data-id="&quot;&gt;&lt;script&gt;">',
            (new AssetTagRenderer())->render($asset, '"'),
        );
    }
}
