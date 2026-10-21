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
use Psr\Log\LogLevel;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\MappedAsset;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\WebLink\GenericLinkProvider;
use Tito10047\UX\Sdc\EventListener\AssetResponseListener;
use Tito10047\UX\Sdc\Service\AssetRegistry;
use Tito10047\UX\Sdc\Tests\TestLogger;

final class AssetResponseListenerTest extends TestCase
{
    private const PAGE = '<html><head>[[ASSETS]]</head><body></body></html>';

    private AssetRegistry $registry;
    private TestLogger $logger;
    private AssetResponseListener $listener;

    protected function setUp(): void
    {
        $this->registry = new AssetRegistry();
        $this->logger = new TestLogger();
        $this->listener = $this->createListener(debug: true);
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

    public function testWarnsInDebugWhenAPageWithAssetsHasNoPlaceholder(): void
    {
        $this->registry->addAsset('Alert/Alert.css', 'css');
        $this->registry->addAsset('Alert/Alert.js', 'js');

        $page = '<html><head><title>Shop</title></head><body></body></html>';
        $response = $this->dispatch(new Response($page), Request::create('/shop?page=2'));

        $this->assertSame($page, $response->getContent());

        $warnings = $this->logger->recordsOf(LogLevel::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertSame(
            ['uri' => '/shop?page=2', 'assets' => 'Alert/Alert.css, Alert/Alert.js', 'placeholder' => '[[ASSETS]]'],
            $warnings[0]['context'],
        );
        $this->assertStringContainsString('{placeholder}', $warnings[0]['message']);
        $this->assertStringContainsString('render_component_assets()', $warnings[0]['message']);
    }

    /**
     * @return iterable<string, array{bool, string, bool, array<string, string>, int}>
     */
    public static function provideResponsesThatAreNotReported(): iterable
    {
        $page = '<html><HEAD></HEAD><body></body></html>';

        // [debug, content, has assets, response headers, request type]
        yield 'not in debug' => [false, $page, true, [], HttpKernelInterface::MAIN_REQUEST];
        yield 'no component with assets' => [true, $page, false, [], HttpKernelInterface::MAIN_REQUEST];
        yield 'placeholder present' => [true, self::PAGE, true, [], HttpKernelInterface::MAIN_REQUEST];
        yield 'fragment without a head' => [true, '<turbo-frame id="cart"><div class="alert"></div></turbo-frame>', true, [], HttpKernelInterface::MAIN_REQUEST];
        yield 'not HTML' => [true, $page, true, ['Content-Type' => 'application/json'], HttpKernelInterface::MAIN_REQUEST];
        yield 'sub-request' => [true, $page, true, [], HttpKernelInterface::SUB_REQUEST];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('provideResponsesThatAreNotReported')]
    public function testDoesNotWarn(bool $debug, string $content, bool $hasAssets, array $headers, int $requestType): void
    {
        $this->listener = $this->createListener($debug);
        if ($hasAssets) {
            $this->registry->addAsset('Alert/Alert.css', 'css');
        }

        $this->dispatch(new Response($content, 200, $headers), null, $requestType);

        $this->assertSame([], $this->logger->records);
    }

    public function testDoesNotWarnLiveComponentRequests(): void
    {
        $this->registry->addAsset('Alert/Alert.css', 'css');

        $request = new Request();
        $request->headers->set('Accept', 'application/vnd.live-component+html');
        $this->dispatch(new Response('<html><head></head><body></body></html>'), $request);

        $this->assertSame([], $this->logger->records);
    }

    public function testWarnsInDebugAboutAnAssetTheAssetMapDoesNotKnow(): void
    {
        $this->registry->addAsset('Alert/Alert.css', 'css');
        $this->registry->addAsset('css/typo.css', 'css');

        $response = $this->dispatch(new Response(self::PAGE));

        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/Alert/Alert-1a2b3c.css">', (string) $response->getContent());
        $this->assertStringContainsString('<link rel="stylesheet" href="css/typo.css">', (string) $response->getContent(), 'Still linked as it is.');

        $warnings = $this->logger->recordsOf(LogLevel::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertSame(['path' => 'css/typo.css'], $warnings[0]['context']);
        $this->assertStringContainsString('"{path}" is not in the asset map', $warnings[0]['message']);
        $this->assertStringContainsString('debug:sdc', $warnings[0]['message']);
    }

    public function testWarnsAboutAnUnknownAssetOfALiveComponentRequestToo(): void
    {
        $this->registry->addAsset('css/typo.css', 'css');

        $request = new Request();
        $request->headers->set('Accept', 'application/vnd.live-component+html');
        $response = $this->dispatch(new Response('<div></div>'), $request);

        $this->assertSame('css/typo.css', $response->headers->get('X-SDC-Assets-CSS'));
        $this->assertCount(1, $this->logger->recordsOf(LogLevel::WARNING));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUrls(): iterable
    {
        yield 'absolute' => ['https://cdn.example.com/lib.css'];
        yield 'protocol-relative' => ['//cdn.example.com/lib.css'];
        yield 'root-relative' => ['/build/app.css'];
        yield 'data URI' => ['data:text/css,body{}'];
    }

    /**
     * A URL is linked as it is on purpose.
     */
    #[DataProvider('provideUrls')]
    public function testDoesNotWarnAboutAUrl(string $url): void
    {
        $this->registry->addAsset($url, 'css');

        $response = $this->dispatch(new Response(self::PAGE));

        $this->assertStringContainsString(htmlspecialchars($url, \ENT_QUOTES), (string) $response->getContent());
        $this->assertSame([], $this->logger->records);
    }

    public function testDoesNotWarnAboutAnUnknownAssetOutsideDebug(): void
    {
        $this->listener = $this->createListener(false);
        $this->registry->addAsset('css/typo.css', 'css');

        $this->dispatch(new Response(self::PAGE));

        $this->assertSame([], $this->logger->records);
    }

    /**
     * The asset map knows Alert/Alert.css and Alert/Alert.js.
     */
    private function createListener(bool $debug): AssetResponseListener
    {
        $assetMapper = $this->createMock(AssetMapperInterface::class);
        $assetMapper->method('getAsset')->willReturnCallback(static fn (string $logicalPath): ?MappedAsset => match ($logicalPath) {
            'Alert/Alert.css' => new MappedAsset($logicalPath, publicPath: '/assets/Alert/Alert-1a2b3c.css'),
            'Alert/Alert.js' => new MappedAsset($logicalPath, publicPath: '/assets/Alert/Alert-4d5e6f.js'),
            default => null,
        });

        return new AssetResponseListener($this->registry, $assetMapper, '[[ASSETS]]', $this->logger, $debug);
    }

    private function dispatch(Response $response, ?Request $request = null, int $requestType = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), $request ?? new Request(), $requestType, $response);

        $this->listener->onKernelResponse($event);

        return $event->getResponse();
    }
}
