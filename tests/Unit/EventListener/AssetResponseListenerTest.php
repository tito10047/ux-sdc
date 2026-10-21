<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\EventListener;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\WebLink\GenericLinkProvider;
use Tito10047\UX\Sdc\EventListener\AssetResponseListener;
use Tito10047\UX\Sdc\Service\AssetRegistry;

final class AssetResponseListenerTest extends TestCase
{
    private const PAGE = '<html><head>[[ASSETS]]</head><body></body></html>';

    private AssetRegistry $registry;
    private AssetResponseListener $listener;

    protected function setUp(): void
    {
        $this->registry = new AssetRegistry();

        $assetMapper = $this->createMock(AssetMapperInterface::class);
        $assetMapper->method('getAsset')->willReturn(null);

        $this->listener = new AssetResponseListener($this->registry, $assetMapper, '[[ASSETS]]');
    }

    public function testOnKernelResponseReplacesPlaceholder(): void
    {
        $this->registry->addAsset('style.css', 'css');
        $this->registry->addAsset('app.js', 'js');

        $request = new Request();
        $response = $this->dispatch(new Response(self::PAGE), $request);

        $this->assertSame(
            '<html><head><link rel="stylesheet" href="style.css">' . "\n" . '<script src="app.js"></script>' . "\n" . '</head><body></body></html>',
            $response->getContent(),
        );

        $links = $request->attributes->get('_links');
        $this->assertInstanceOf(GenericLinkProvider::class, $links);
        $this->assertCount(2, $links->getLinks());
    }

    public function testInjectsOnceWhenThePlaceholderAppearsSeveralTimes(): void
    {
        $this->registry->addAsset('style.css', 'css');
        $this->registry->addAsset('app.js', 'js');

        $response = $this->dispatch(new Response('<head>[[ASSETS]]</head><body>[[ASSETS]]<p>[[ASSETS]]</p></body>'));

        $this->assertSame(
            '<head><link rel="stylesheet" href="style.css">' . "\n" . '<script src="app.js"></script>' . "\n" . '</head><body><p></p></body>',
            $response->getContent(),
        );
    }

    public function testRemovesThePlaceholderWhenNoComponentHasAssets(): void
    {
        $request = new Request();
        $response = $this->dispatch(new Response(self::PAGE), $request);

        $this->assertSame('<html><head></head><body></body></html>', $response->getContent());
        $this->assertFalse($request->attributes->has('_links'));
    }

    public function testUpdatesContentLengthSetBeforeTheInjection(): void
    {
        $this->registry->addAsset('style.css', 'css');

        $response = $this->dispatch(new Response(self::PAGE, 200, ['Content-Length' => (string) \strlen(self::PAGE)]));

        $this->assertSame((string) \strlen((string) $response->getContent()), $response->headers->get('Content-Length'));
    }

    public function testDoesNotAddContentLength(): void
    {
        $this->registry->addAsset('style.css', 'css');

        $response = $this->dispatch(new Response(self::PAGE));

        $this->assertFalse($response->headers->has('Content-Length'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonHtmlContentTypes(): iterable
    {
        yield 'JSON' => ['application/json'];
        yield 'plain text' => ['text/plain; charset=UTF-8'];
        yield 'JavaScript' => ['text/javascript'];
    }

    #[DataProvider('provideNonHtmlContentTypes')]
    public function testLeavesNonHtmlResponsesAlone(string $contentType): void
    {
        $this->registry->addAsset('style.css', 'css');

        $request = new Request();
        $response = $this->dispatch(new Response(self::PAGE, 200, ['Content-Type' => $contentType]), $request);

        $this->assertSame(self::PAGE, $response->getContent());
        $this->assertFalse($request->attributes->has('_links'));
    }

    public function testInjectsIntoXhtmlResponses(): void
    {
        $this->registry->addAsset('style.css', 'css');

        $response = $this->dispatch(new Response(self::PAGE, 200, ['Content-Type' => 'application/xhtml+xml']));

        $this->assertStringContainsString('<link rel="stylesheet" href="style.css">', (string) $response->getContent());
    }

    public function testResponseWithoutContentTypeFollowsTheRequestFormat(): void
    {
        $this->registry->addAsset('style.css', 'css');

        $request = new Request();
        $request->setRequestFormat('json');
        $response = $this->dispatch(new Response(self::PAGE), $request);

        $this->assertSame(self::PAGE, $response->getContent());
    }

    public function testLeavesStreamedResponsesAlone(): void
    {
        $this->registry->addAsset('style.css', 'css');

        $request = new Request();
        $response = $this->dispatch(new StreamedResponse(static function (): void {
            echo self::PAGE;
        }), $request);

        $this->assertFalse($response->getContent());
        $this->assertFalse($request->attributes->has('_links'));
    }

    public function testLeavesSubRequestsAlone(): void
    {
        $this->registry->addAsset('style.css', 'css');

        $request = new Request();
        $response = $this->dispatch(new Response(self::PAGE), $request, HttpKernelInterface::SUB_REQUEST);

        $this->assertSame(self::PAGE, $response->getContent());
        $this->assertFalse($request->attributes->has('_links'));
    }

    public function testLeavesLiveComponentSubRequestsAlone(): void
    {
        $this->registry->addAsset('style.css', 'css');

        $request = new Request();
        $request->headers->set('Accept', 'application/vnd.live-component+html');
        $response = $this->dispatch(new Response('<div></div>'), $request, HttpKernelInterface::SUB_REQUEST);

        $this->assertFalse($response->headers->has('X-SDC-Assets-CSS'));
    }

    public function testSendsAssetsAsHeadersToLiveComponentRequests(): void
    {
        $this->registry->addAsset('style.css', 'css');
        $this->registry->addAsset('app.js', 'js');
        $this->registry->addAsset('font.woff2', '');

        $request = new Request();
        $request->headers->set('Accept', 'application/vnd.live-component+html');
        $response = $this->dispatch(new Response('<div></div>'), $request);

        $this->assertSame('style.css', $response->headers->get('X-SDC-Assets-CSS'));
        $this->assertSame('app.js', $response->headers->get('X-SDC-Assets-JS'));
        $this->assertSame('<div></div>', $response->getContent());
    }

    public function testRendersBooleanAttributesLikeTwigComponents(): void
    {
        $this->registry->addAsset('app.js', 'js', attributes: ['defer' => true, 'async' => false, 'nonce' => null, 'data-id' => 'a"b']);

        $response = $this->dispatch(new Response(self::PAGE));

        $this->assertStringContainsString('<script src="app.js" defer data-id="a&quot;b"></script>', (string) $response->getContent());
    }

    public function testEscapesTheAssetUrl(): void
    {
        $this->registry->addAsset('https://cdn.example.com/lib.css?v=1&theme="dark"', 'css');

        $response = $this->dispatch(new Response(self::PAGE));

        $this->assertStringContainsString(
            '<link rel="stylesheet" href="https://cdn.example.com/lib.css?v=1&amp;theme=&quot;dark&quot;">',
            (string) $response->getContent(),
        );
    }

    private function dispatch(Response $response, ?Request $request = null, int $requestType = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), $request ?? new Request(), $requestType, $response);

        $this->listener->onKernelResponse($event);

        return $event->getResponse();
    }
}
