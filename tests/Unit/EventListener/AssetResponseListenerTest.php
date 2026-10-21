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
use Tito10047\UX\Sdc\Csp\NonceProviderInterface;
use Tito10047\UX\Sdc\EventListener\AssetResponseListener;
use Tito10047\UX\Sdc\Response\AssetResolver;
use Tito10047\UX\Sdc\Response\AssetTagRenderer;
use Tito10047\UX\Sdc\Response\FragmentAssetHeader;
use Tito10047\UX\Sdc\Response\PageAssetInjector;
use Tito10047\UX\Sdc\Response\ResponseClassifier;
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
            '<html><head><link rel="stylesheet" href="style.css" data-turbo-track="dynamic">' . "\n" . '<script src="app.js" type="module"></script>' . "\n" . '</head><body></body></html>',
            $response->getContent(),
        );

        $this->assertSame(['style.css' => 'style'], $this->preloads($request), 'A module script gets no preload.');
    }

    public function testInjectsOnceWhenThePlaceholderAppearsSeveralTimes(): void
    {
        $this->registry->addAsset('style.css', 'css');
        $this->registry->addAsset('app.js', 'js');

        $response = $this->dispatch(new Response('<head>[[ASSETS]]</head><body>[[ASSETS]]<p>[[ASSETS]]</p></body>'));

        $this->assertSame(
            '<head><link rel="stylesheet" href="style.css" data-turbo-track="dynamic">' . "\n" . '<script src="app.js" type="module"></script>' . "\n" . '</head><body><p></p></body>',
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

        $this->assertStringContainsString('<link rel="stylesheet" href="style.css" data-turbo-track="dynamic">', (string) $response->getContent());
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
        $this->assertFalse($response->headers->has('X-SDC-Assets'));
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

        $this->assertFalse($response->headers->has('X-SDC-Assets'));
    }

    public function testListsTheAssetsOfALiveComponentRequestInAHeader(): void
    {
        $this->registry->addAsset('style.css', 'css');
        $this->registry->addAsset('app.js', 'js');
        $this->registry->addAsset('font.woff2', '');

        $request = new Request();
        $request->headers->set('Accept', 'application/vnd.live-component+html');
        $response = $this->dispatch(new Response('<div></div>'), $request);

        $this->assertSame(
            '{"v":1,"assets":[{"type":"css","href":"style.css","attributes":{"data-turbo-track":"dynamic"}},{"type":"module","href":"app.js"}]}',
            $response->headers->get('X-SDC-Assets'),
        );
        $this->assertSame('<div></div>', $response->getContent());
        $this->assertFalse($request->attributes->has('_links'));
    }

    /**
     * @return iterable<string, array{Request, Response}>
     */
    public static function provideFragmentResponses(): iterable
    {
        $page = '<html><head><title>Cart</title>[[ASSETS]]</head><body><turbo-frame id="cart"></turbo-frame></body></html>';

        $request = new Request();
        $request->headers->set('Accept', 'application/vnd.live-component+html');
        yield 'Live Component request' => [$request, new Response('<div></div>')];

        $request = new Request();
        $request->headers->set('Accept', 'application/vnd.live-component+html, */*;q=0.8');
        yield 'Live Component request accepting more types' => [$request, new Response('<div></div>')];

        yield 'Live Component response' => [new Request(), new Response('<div></div>', 200, ['Content-Type' => 'application/vnd.live-component+html'])];

        $request = new Request();
        $request->headers->set('Turbo-Frame', 'cart');
        yield 'Turbo Frame request answered with a whole page' => [$request, new Response($page)];

        $request = new Request();
        $request->headers->set('Turbo-Frame', 'cart');
        yield 'Turbo Frame request answered with a whole page without the placeholder' => [$request, new Response(str_replace('[[ASSETS]]', '', $page))];

        yield 'Turbo Stream response' => [new Request(), new Response('<turbo-stream action="append" target="cart"><template>[[ASSETS]]</template></turbo-stream>', 200, ['Content-Type' => 'text/vnd.turbo-stream.html; charset=UTF-8'])];

        // What ux-turbo does: the format is registered, the controller sets it,
        // Response::prepare() derives the Content-Type after this listener ran.
        $request = new Request();
        $request->setFormat('turbo_stream', 'text/vnd.turbo-stream.html');
        $request->setRequestFormat('turbo_stream');
        yield 'Turbo Stream request format' => [$request, new Response('<turbo-stream action="append" target="cart"><template></template></turbo-stream>')];
    }

    /**
     * The client keeps only a part of the response, so tags would be lost even
     * where the response has the placeholder; the content is left alone.
     */
    #[DataProvider('provideFragmentResponses')]
    public function testListsTheAssetsOfAFragmentResponseInAHeader(Request $request, Response $response): void
    {
        $provider = $this->createNonceProvider('script-n0nce', 'style-n0nce');
        $this->listener = $this->createListener(true, $provider);
        $this->registry->addAsset('Alert/Alert.css', 'css');
        $content = $response->getContent();

        $response = $this->dispatch($response, $request);

        $this->assertSame(
            '{"v":1,"assets":[{"type":"css","href":"/assets/Alert/Alert-1a2b3c.css","attributes":{"data-turbo-track":"dynamic"}}]}',
            $response->headers->get('X-SDC-Assets'),
        );
        $this->assertSame($content, $response->getContent());
        $this->assertFalse($request->attributes->has('_links'));
        $this->assertSame(['script' => 0, 'style' => 0], $provider->calls, 'The nonce is the one of the page the assets are added to.');
        $this->assertSame([], $this->logger->records, 'A fragment is not a page missing its placeholder.');
    }

    /**
     * A Turbo Drive visit, or a Turbo form submission answered with a page
     * (Turbo accepts streams with every submission): Turbo merges the <head>.
     */
    public function testInjectsIntoAPageRequestedByTurbo(): void
    {
        $this->registry->addAsset('style.css', 'css');

        $request = new Request();
        $request->headers->set('Accept', 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml');
        $response = $this->dispatch(new Response(self::PAGE), $request);

        $this->assertStringContainsString('<link rel="stylesheet" href="style.css" data-turbo-track="dynamic">', (string) $response->getContent());
        $this->assertFalse($response->headers->has('X-SDC-Assets'));
    }

    /**
     * An htmx partial, a fragment fetch()ed by the application: the header is
     * the only way to tell the page about the assets.
     */
    public function testListsTheAssetsOfAResponseWithoutThePlaceholderInAHeader(): void
    {
        $this->registry->addAsset('Alert/Alert.js', 'js');

        $response = $this->dispatch(new Response('<div class="alert"></div>'));

        $this->assertSame('{"v":1,"assets":[{"type":"module","href":"/assets/Alert/Alert-4d5e6f.js"}]}', $response->headers->get('X-SDC-Assets'));
        $this->assertSame('<div class="alert"></div>', $response->getContent());
        $this->assertSame([], $this->logger->records);
    }

    /**
     * @return iterable<string, array{Response, int}>
     */
    public static function provideResponsesWithoutHeader(): iterable
    {
        // [response, request type]
        yield 'page with the placeholder' => [new Response(self::PAGE), HttpKernelInterface::MAIN_REQUEST];
        yield 'not HTML' => [new Response('{}', 200, ['Content-Type' => 'application/json']), HttpKernelInterface::MAIN_REQUEST];
        yield 'sub-request' => [new Response('<div></div>'), HttpKernelInterface::SUB_REQUEST];
    }

    #[DataProvider('provideResponsesWithoutHeader')]
    public function testSendsNoHeader(Response $response, int $requestType): void
    {
        $this->registry->addAsset('style.css', 'css');

        $response = $this->dispatch($response, null, $requestType);

        $this->assertFalse($response->headers->has('X-SDC-Assets'));
    }

    /**
     * A font is only preloaded, which a fragment has no use for.
     */
    public function testSendsNoHeaderWithoutAssetsToList(): void
    {
        $this->registry->addAsset('font.woff2', '');

        $request = new Request();
        $request->headers->set('Turbo-Frame', 'cart');
        $response = $this->dispatch(new Response('<turbo-frame id="cart"></turbo-frame>'), $request);

        $this->assertFalse($response->headers->has('X-SDC-Assets'));
    }

    /**
     * Each entry carries what the tag of a full page would get, but the nonce
     * and the `type` that makes a module script.
     */
    public function testTheHeaderCarriesTheAttributesOfTheTags(): void
    {
        $this->registry->addAsset('app.js', 'js', attributes: ['defer' => true, 'async' => false, 'data-id' => 'a"b', 'nonce' => null]);
        $this->registry->addAsset('legacy.js', 'js', attributes: ['type' => false]);
        $this->registry->addAsset('old.js', 'js', attributes: ['type' => 'text/javascript', 'integrity' => 'sha384-x', 'crossorigin' => 'anonymous']);
        $this->registry->addAsset('own.css', 'css', attributes: ['nonce' => 'own', 'media' => 'print']);
        $this->registry->addAsset('untracked.css', 'css', attributes: ['data-turbo-track' => false]);

        $response = $this->dispatch(new Response('<div></div>'));

        $this->assertSame(
            ['v' => 1, 'assets' => [
                ['type' => 'module', 'href' => 'app.js', 'attributes' => ['nonce' => false, 'defer' => true, 'data-id' => 'a"b']],
                ['type' => 'script', 'href' => 'legacy.js'],
                ['type' => 'script', 'href' => 'old.js', 'attributes' => ['type' => 'text/javascript', 'integrity' => 'sha384-x', 'crossorigin' => 'anonymous']],
                ['type' => 'css', 'href' => 'own.css', 'attributes' => ['nonce' => 'own', 'data-turbo-track' => 'dynamic', 'media' => 'print']],
                ['type' => 'css', 'href' => 'untracked.css'],
            ]],
            json_decode((string) $response->headers->get('X-SDC-Assets'), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * A URL with a comma, which split the comma-separated headers of earlier
     * versions, and the same file asked for by its logical path and its URL.
     */
    public function testListsEachAssetOnce(): void
    {
        $this->registry->addAsset('Alert/Alert.css', 'css');
        $this->registry->addAsset('/assets/Alert/Alert-1a2b3c.css', 'css');
        $this->registry->addAsset('https://cdn.example.com/a,b.js', 'js');

        $response = $this->dispatch(new Response('<div></div>'));

        $this->assertSame(
            '{"v":1,"assets":[{"type":"css","href":"/assets/Alert/Alert-1a2b3c.css","attributes":{"data-turbo-track":"dynamic"}},{"type":"module","href":"https://cdn.example.com/a,b.js"}]}',
            $response->headers->get('X-SDC-Assets'),
        );
    }

    public function testTheHeaderIsAscii(): void
    {
        $this->registry->addAsset('https://cdn.example.com/štýl.css', 'css', attributes: ['data-turbo-track' => false]);
        $this->registry->addAsset("https://cdn.example.com/\xff.js", 'js');

        $value = (string) $this->dispatch(new Response('<div></div>'))->headers->get('X-SDC-Assets');

        $this->assertSame(
            '{"v":1,"assets":[{"type":"css","href":"https://cdn.example.com/\\u0161t\\u00fdl.css"},{"type":"module","href":"https://cdn.example.com/\\ufffd.js"}]}',
            $value,
        );
        $this->assertSame(['https://cdn.example.com/štýl.css', "https://cdn.example.com/\u{fffd}.js"], array_column(json_decode($value, true, flags: \JSON_THROW_ON_ERROR)['assets'], 'href'));
    }

    public function testWarnsInDebugAboutAHeaderTooLongForAProxy(): void
    {
        for ($i = 0; $i < 40; ++$i) {
            $this->registry->addAsset(sprintf('https://cdn.example.com/components/Component%d/Component%d.css', $i, $i), 'css');
        }

        $response = $this->dispatch(new Response('<div></div>'), Request::create('/cart'));

        $this->assertTrue($response->headers->has('X-SDC-Assets'), 'Still sent.');
        $warnings = $this->logger->recordsOf(LogLevel::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertSame(
            ['header' => 'X-SDC-Assets', 'uri' => '/cart', 'size' => \strlen((string) $response->headers->get('X-SDC-Assets')), 'count' => 40],
            $warnings[0]['context'],
        );
        $this->assertStringContainsString('fastcgi_buffer_size', $warnings[0]['message']);

        $this->logger->records = [];
        $this->listener = $this->createListener(false);
        $this->dispatch(new Response('<div></div>'));
        $this->assertSame([], $this->logger->records, 'Not outside debug.');
    }

    public function testDoesNotWarnAboutAHeaderOfUsualSize(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->registry->addAsset(sprintf('/assets/UI/Component%d/Component%d-0123456789abcdef.css', $i, $i), 'css');
            $this->registry->addAsset(sprintf('/assets/UI/Component%d/Component%d-0123456789abcdef.js', $i, $i), 'js');
        }

        $this->dispatch(new Response('<div></div>'));

        $this->assertSame([], $this->logger->records);
    }

    public function testRendersBooleanAttributesLikeTwigComponents(): void
    {
        $this->registry->addAsset('app.js', 'js', attributes: ['defer' => true, 'async' => false, 'nonce' => null, 'data-id' => 'a"b']);

        $response = $this->dispatch(new Response(self::PAGE));

        $this->assertStringContainsString('<script src="app.js" type="module" defer data-id="a&quot;b"></script>', (string) $response->getContent());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideClassicScripts(): iterable
    {
        yield 'type left out' => [['type' => false], '<script src="legacy.js"></script>'];
        yield 'type null' => [['type' => null], '<script src="legacy.js"></script>'];
        yield 'another type' => [['type' => 'text/javascript'], '<script src="legacy.js" type="text/javascript"></script>'];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('provideClassicScripts')]
    public function testAnAssetCanOptOutOfModules(array $attributes, string $tag): void
    {
        $this->registry->addAsset('legacy.js', 'js', attributes: $attributes);

        $request = new Request();
        $response = $this->dispatch(new Response(self::PAGE), $request);

        $this->assertStringContainsString($tag . "\n", (string) $response->getContent());
        $this->assertSame(['legacy.js' => 'script'], $this->preloads($request));
    }

    public function testAnExplicitModuleTypeIsAModule(): void
    {
        $this->registry->addAsset('app.js', 'js', attributes: ['type' => 'MODULE']);

        $request = new Request();
        $response = $this->dispatch(new Response(self::PAGE), $request);

        $this->assertStringContainsString('<script src="app.js" type="MODULE"></script>', (string) $response->getContent());
        $this->assertFalse($request->attributes->has('_links'));
    }

    public function testPutsTheNoncesOfTheProviderOnTheTags(): void
    {
        $provider = $this->createNonceProvider('script-n0nce', 'style"n0nce');
        $this->listener = $this->createListener(true, $provider);
        $this->registry->addAsset('style.css', 'css');
        $this->registry->addAsset('app.js', 'js');
        $this->registry->addAsset('legacy.js', 'js', attributes: ['type' => false]);

        $response = $this->dispatch(new Response(self::PAGE));

        $this->assertSame(
            '<html><head><link rel="stylesheet" href="style.css" nonce="style&quot;n0nce" data-turbo-track="dynamic">' . "\n"
            . '<script src="app.js" type="module" nonce="script-n0nce"></script>' . "\n"
            . '<script src="legacy.js" nonce="script-n0nce"></script>' . "\n"
            . '</head><body></body></html>',
            $response->getContent(),
        );
        $this->assertSame(['script' => 1, 'style' => 1], $provider->calls, 'Each nonce is asked for once per response.');
    }

    public function testTheNonceAttributeOfAnAssetWins(): void
    {
        $this->listener = $this->createListener(true, $this->createNonceProvider('n0nce', 'n0nce'));
        $this->registry->addAsset('app.js', 'js', attributes: ['nonce' => false]);
        $this->registry->addAsset('style.css', 'css', attributes: ['nonce' => 'own']);

        $content = (string) $this->dispatch(new Response(self::PAGE))->getContent();

        $this->assertStringContainsString('<script src="app.js" type="module"></script>', $content);
        $this->assertStringContainsString('<link rel="stylesheet" href="style.css" nonce="own" data-turbo-track="dynamic">', $content);
    }

    public function testAStylesheetCanOptOutOfTurboTracking(): void
    {
        $this->registry->addAsset('style.css', 'css', attributes: ['data-turbo-track' => false]);
        $this->registry->addAsset('app.css', 'css', attributes: ['data-turbo-track' => 'reload']);

        $content = (string) $this->dispatch(new Response(self::PAGE))->getContent();

        $this->assertStringContainsString('<link rel="stylesheet" href="style.css">', $content);
        $this->assertStringContainsString('<link rel="stylesheet" href="app.css" data-turbo-track="reload">', $content);
    }

    public function testInjectsWithoutANonceWhenTheProviderHasNone(): void
    {
        $this->listener = $this->createListener(true, $this->createNonceProvider(null, null));
        $this->registry->addAsset('style.css', 'css');
        $this->registry->addAsset('app.js', 'js');

        $content = (string) $this->dispatch(new Response(self::PAGE))->getContent();

        $this->assertStringContainsString('<link rel="stylesheet" href="style.css" data-turbo-track="dynamic">', $content);
        $this->assertStringContainsString('<script src="app.js" type="module"></script>', $content);
    }

    /**
     * NelmioSecurityBundle adds a nonce to the policy when it is asked for one;
     * a page without component scripts must not get a script nonce it does not use.
     */
    public function testAsksOnlyForTheNoncesOfTheTagsInjected(): void
    {
        $provider = $this->createNonceProvider('script-n0nce', 'style-n0nce');
        $this->listener = $this->createListener(true, $provider);
        $this->registry->addAsset('style.css', 'css');

        $this->dispatch(new Response(self::PAGE));

        $this->assertSame(['script' => 0, 'style' => 1], $provider->calls);
    }

    /**
     * @return iterable<string, array{Request, Response}>
     */
    public static function provideResponsesWithoutTags(): iterable
    {
        $live = new Request();
        $live->headers->set('Accept', 'application/vnd.live-component+html');

        yield 'Live Component request' => [$live, new Response('<div></div>')];
        yield 'no placeholder' => [new Request(), new Response('<html><head></head><body></body></html>')];
        yield 'not HTML' => [new Request(), new Response(self::PAGE, 200, ['Content-Type' => 'application/json'])];
    }

    #[DataProvider('provideResponsesWithoutTags')]
    public function testDoesNotAskForNoncesWhenNoTagIsInjected(Request $request, Response $response): void
    {
        $provider = $this->createNonceProvider('script-n0nce', 'style-n0nce');
        $this->listener = $this->createListener(true, $provider);
        $this->registry->addAsset('style.css', 'css');
        $this->registry->addAsset('app.js', 'js');

        $this->dispatch($response, $request);

        $this->assertSame(['script' => 0, 'style' => 0], $provider->calls);
    }

    public function testWarnsInDebugWhenTheImportMapComesAfterModuleScripts(): void
    {
        $this->registry->addAsset('Alert/Alert.js', 'js');

        $page = '<html><head>[[ASSETS]]<script type="importmap">{"imports": {}}</script></head><body></body></html>';
        $this->dispatch(new Response($page), Request::create('/shop'));

        $warnings = $this->logger->recordsOf(LogLevel::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertSame(['uri' => '/shop'], $warnings[0]['context']);
        $this->assertStringContainsString('before its import map', $warnings[0]['message']);
        $this->assertStringContainsString('after {{ importmap() }}', $warnings[0]['message']);
    }

    /**
     * @return iterable<string, array{bool, string, array<string, mixed>}>
     */
    public static function providePagesWithoutImportMapProblem(): iterable
    {
        $after = '<html><head>[[ASSETS]]<SCRIPT type=importmap>{}</SCRIPT></head><body></body></html>';

        // [debug, content, script attributes]
        yield 'import map first' => [true, '<html><head><script type="importmap">{}</script>' . "\n" . '[[ASSETS]]</head><body>[[ASSETS]]</body></html>', []];
        yield 'no import map' => [true, self::PAGE, []];
        yield 'classic scripts only' => [true, $after, ['type' => false]];
        yield 'not in debug' => [false, $after, []];
        yield 'another attribute ending in type' => [true, '<html><head>[[ASSETS]]<script data-type="importmap" src="x.js"></script></head></html>', []];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('providePagesWithoutImportMapProblem')]
    public function testDoesNotWarnAboutTheImportMap(bool $debug, string $content, array $attributes): void
    {
        $this->listener = $this->createListener($debug);
        $this->registry->addAsset('Alert/Alert.js', 'js', attributes: $attributes);

        $this->dispatch(new Response($content));

        $this->assertSame([], $this->logger->records);
    }

    public function testWarnsAboutAnImportMapWrittenInAnyCase(): void
    {
        $this->registry->addAsset('Alert/Alert.js', 'js');

        $this->dispatch(new Response('<html><head>[[ASSETS]]<SCRIPT nonce="x" TYPE=\'importmap\'>{}</SCRIPT></head></html>'));

        $this->assertCount(1, $this->logger->recordsOf(LogLevel::WARNING));
    }

    public function testEscapesTheAssetUrl(): void
    {
        $this->registry->addAsset('https://cdn.example.com/lib.css?v=1&theme="dark"', 'css');

        $response = $this->dispatch(new Response(self::PAGE));

        $this->assertStringContainsString(
            '<link rel="stylesheet" href="https://cdn.example.com/lib.css?v=1&amp;theme=&quot;dark&quot;" data-turbo-track="dynamic">',
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
        $this->assertTrue($response->headers->has('X-SDC-Assets'), 'Of use to a Turbo Drive visit, at least.');

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

        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/Alert/Alert-1a2b3c.css" data-turbo-track="dynamic">', (string) $response->getContent());
        $this->assertStringContainsString('<link rel="stylesheet" href="css/typo.css" data-turbo-track="dynamic">', (string) $response->getContent(), 'Still linked as it is.');

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

        $this->assertStringContainsString('"href":"css/typo.css"', (string) $response->headers->get('X-SDC-Assets'));
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
     * The listener with its collaborators, all of them real: this test covers
     * the output of the whole response path. The asset map knows
     * Alert/Alert.css and Alert/Alert.js.
     */
    private function createListener(bool $debug, ?NonceProviderInterface $nonceProvider = null): AssetResponseListener
    {
        $assetMapper = $this->createMock(AssetMapperInterface::class);
        $assetMapper->method('getAsset')->willReturnCallback(static fn (string $logicalPath): ?MappedAsset => match ($logicalPath) {
            'Alert/Alert.css' => new MappedAsset($logicalPath, publicPath: '/assets/Alert/Alert-1a2b3c.css'),
            'Alert/Alert.js' => new MappedAsset($logicalPath, publicPath: '/assets/Alert/Alert-4d5e6f.js'),
            default => null,
        });

        return new AssetResponseListener(
            $this->registry,
            new AssetResolver($assetMapper, $this->logger, $debug),
            new ResponseClassifier(),
            new PageAssetInjector(new AssetTagRenderer(), '[[ASSETS]]', $nonceProvider, $this->logger, $debug),
            new FragmentAssetHeader($this->logger, $debug),
            '[[ASSETS]]',
            $this->logger,
            $debug,
        );
    }

    /**
     * Counts how often each nonce is asked for.
     */
    private function createNonceProvider(?string $scriptNonce, ?string $styleNonce): NonceProviderInterface
    {
        return new class ($scriptNonce, $styleNonce) implements NonceProviderInterface {
            /** @var array{script: int, style: int} */
            public array $calls = ['script' => 0, 'style' => 0];

            public function __construct(
                private ?string $scriptNonce,
                private ?string $styleNonce,
            ) {
            }

            public function getScriptNonce(): ?string
            {
                ++$this->calls['script'];

                return $this->scriptNonce;
            }

            public function getStyleNonce(): ?string
            {
                ++$this->calls['style'];

                return $this->styleNonce;
            }
        };
    }

    /**
     * The preload links stored for WebLink, `href => as`.
     *
     * @return array<string, string>
     */
    private function preloads(Request $request): array
    {
        $links = $request->attributes->get('_links');
        $this->assertInstanceOf(GenericLinkProvider::class, $links);

        $preloads = [];
        foreach ($links->getLinks() as $link) {
            $this->assertSame(['preload'], $link->getRels());
            $preloads[$link->getHref()] = $link->getAttributes()['as'];
        }

        return $preloads;
    }

    private function dispatch(Response $response, ?Request $request = null, int $requestType = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), $request ?? new Request(), $requestType, $response);

        $this->listener->onKernelResponse($event);

        return $event->getResponse();
    }
}
