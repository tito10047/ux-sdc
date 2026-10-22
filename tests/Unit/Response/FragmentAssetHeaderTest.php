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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tito10047\UX\Sdc\EventListener\AssetResponseListener;
use Tito10047\UX\Sdc\Response\FragmentAssetHeader;
use Tito10047\UX\Sdc\Response\ResolvedAsset;
use Tito10047\UX\Sdc\Tests\TestLogger;

final class FragmentAssetHeaderTest extends TestCase
{
    public function testTheListenerKeepsPublishingTheHeaderNameAndVersion(): void
    {
        $this->assertSame('X-SDC-Assets', AssetResponseListener::HEADER);
        $this->assertSame(FragmentAssetHeader::NAME, AssetResponseListener::HEADER);
        $this->assertSame(1, AssetResponseListener::HEADER_VERSION);
        $this->assertSame(FragmentAssetHeader::VERSION, AssetResponseListener::HEADER_VERSION);
    }

    public function testListsTheAssetsInTheOrderGiven(): void
    {
        $response = new Response();

        (new FragmentAssetHeader())->addTo(new Request(), $response, [
            new ResolvedAsset('/a.css', 'css'),
            new ResolvedAsset('/a.js', 'js'),
            new ResolvedAsset('/legacy.js', 'js', ['type' => false, 'defer' => true]),
        ]);

        $this->assertSame(
            '{"v":1,"assets":[{"type":"css","href":"/a.css","attributes":{"data-turbo-track":"dynamic"}},{"type":"module","href":"/a.js"},{"type":"script","href":"/legacy.js","attributes":{"defer":true}}]}',
            $response->headers->get('X-SDC-Assets'),
        );
    }

    /**
     * The client uses the nonce of the page, unless the asset sets its own or
     * turns it off.
     */
    public function testCarriesOnlyTheNonceTheAssetSets(): void
    {
        $response = new Response();

        (new FragmentAssetHeader())->addTo(new Request(), $response, [
            new ResolvedAsset('/own.css', 'css', ['nonce' => 'own', 'data-turbo-track' => false]),
            new ResolvedAsset('/none.js', 'js', ['nonce' => false]),
        ]);

        $this->assertSame(
            '{"v":1,"assets":[{"type":"css","href":"/own.css","attributes":{"nonce":"own"}},{"type":"module","href":"/none.js","attributes":{"nonce":false}}]}',
            $response->headers->get('X-SDC-Assets'),
        );
    }

    public function testListsAnAssetOfTheSameKindAndPathOnce(): void
    {
        $response = new Response();

        (new FragmentAssetHeader())->addTo(new Request(), $response, [
            new ResolvedAsset('/a.css', 'css'),
            new ResolvedAsset('/a.css', 'css', ['media' => 'print']),
            new ResolvedAsset('/a.js', 'js'),
            new ResolvedAsset('/a.js', 'js', ['type' => false]),
        ]);

        $this->assertSame(
            '{"v":1,"assets":[{"type":"css","href":"/a.css","attributes":{"data-turbo-track":"dynamic"}},{"type":"module","href":"/a.js"},{"type":"script","href":"/a.js"}]}',
            $response->headers->get('X-SDC-Assets'),
        );
    }

    public function testSendsNoHeaderWithoutAssets(): void
    {
        $response = new Response();

        (new FragmentAssetHeader())->addTo(new Request(), $response, []);

        $this->assertFalse($response->headers->has('X-SDC-Assets'));
    }

    /**
     * The fragment inserts what uses a font or an image as soon as it
     * arrives: a preload would come no earlier. Version 1 stays as it is.
     */
    public function testLeavesFontsAndImagesOut(): void
    {
        $response = new Response();

        (new FragmentAssetHeader())->addTo(new Request(), $response, [
            new ResolvedAsset('/inter.woff2', 'font'),
            new ResolvedAsset('/a.css', 'css'),
            new ResolvedAsset('/hero.avif', 'image'),
        ]);

        $this->assertSame(
            '{"v":1,"assets":[{"type":"css","href":"/a.css","attributes":{"data-turbo-track":"dynamic"}}]}',
            $response->headers->get('X-SDC-Assets'),
        );

        $response = new Response();
        (new FragmentAssetHeader())->addTo(new Request(), $response, [new ResolvedAsset('/inter.woff2', 'font')]);
        $this->assertFalse($response->headers->has('X-SDC-Assets'), 'Nothing to list, no header.');
    }

    public function testWarnsInDebugAboutAHeaderTooLongForAProxy(): void
    {
        $assets = [];
        for ($i = 0; $i < 40; ++$i) {
            $assets[] = new ResolvedAsset(sprintf('https://cdn.example.com/components/Component%d/Component%d.css', $i, $i), 'css');
        }

        $logger = new TestLogger();
        $response = new Response();
        (new FragmentAssetHeader($logger, true))->addTo(Request::create('/cart'), $response, $assets);

        $this->assertCount(1, $logger->records);
        $this->assertSame(
            ['header' => 'X-SDC-Assets', 'uri' => '/cart', 'size' => \strlen((string) $response->headers->get('X-SDC-Assets')), 'count' => 40],
            $logger->records[0]['context'],
        );

        $logger->records = [];
        (new FragmentAssetHeader($logger, false))->addTo(Request::create('/cart'), new Response(), $assets);
        $this->assertSame([], $logger->records, 'Not outside debug.');
    }
}
