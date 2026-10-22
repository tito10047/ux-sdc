<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration;

use Psr\Log\LogLevel;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\HttpFoundation\Response;
use Tito10047\UX\Sdc\Tests\TestLogger;

/**
 * Responses the client inserts only a part of get the assets of their
 * components in the `X-SDC-Assets` header instead of tags; a page gets tags.
 * Each request renders the AutoDiscovery component, which has a stylesheet and
 * a module script.
 */
final class FragmentAssetsFunctionalTest extends FunctionalTestCase
{
    /**
     * Turbo keeps only the frame of the response and drops its <head>, with the
     * tags in it: the response is left alone and the assets go in the header.
     */
    public function testTurboFrameRequestGetsTheHeaderEvenForAWholePage(): void
    {
        $client = self::createClient(['compiler_passes' => [new RegisterTestLoggerPass()]]);
        $client->request('GET', '/page/auto_discovery.html.twig', server: ['HTTP_TURBO_FRAME' => 'content']);

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        $this->assertHeaderListsTheComponentAssets($response);
        $this->assertStringContainsString('__UX_TWIG_COMPONENT_ASSETS__', (string) $response->getContent(), 'No tags in place of the placeholder.');
        $this->assertStringNotContainsString('<link rel="stylesheet"', (string) $response->getContent());
        $this->assertNull($response->headers->get('Link'), 'Nothing to preload for a page that is not loaded.');
    }

    public function testTurboFrameResponseWithoutAHeadIsNotReported(): void
    {
        $client = self::createClient(['compiler_passes' => [new RegisterTestLoggerPass()]]);
        $client->request('GET', '/page/no_placeholder.html.twig', server: ['HTTP_TURBO_FRAME' => 'content']);

        self::assertResponseIsSuccessful();
        $this->assertHeaderListsTheComponentAssets($client->getResponse());
        $this->assertSame([], $this->logger()->recordsOf(LogLevel::WARNING), 'A frame response legitimately has no placeholder.');
    }

    /**
     * A controller answering Turbo Streams sets the request format; the
     * Content-Type follows from it only after the listener ran.
     */
    public function testTurboStreamResponseGetsTheHeader(): void
    {
        $client = self::createClient(['framework' => ['request' => ['formats' => ['turbo_stream' => 'text/vnd.turbo-stream.html']]]]);
        $client->request('GET', '/stream/turbo_stream.html.twig', server: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml']);

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        $this->assertStringStartsWith('text/vnd.turbo-stream.html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<turbo-stream action="append" target="list">', (string) $response->getContent());
        $this->assertHeaderListsTheComponentAssets($response);
    }

    /**
     * A Turbo Drive visit, or a form submission answered with a page — Turbo
     * accepts streams with every submission — gets tags, which Turbo merges
     * into the <head> of the page it shows.
     */
    public function testPageRequestedByTurboGetsTags(): void
    {
        $client = self::createClient();
        $client->request('GET', '/page/auto_discovery.html.twig', server: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml']);

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        [$css] = $this->componentAssets();
        $this->assertStringContainsString(\sprintf('<link rel="stylesheet" href="%s" data-turbo-track="dynamic">', $css), (string) $response->getContent());
        $this->assertFalse($response->headers->has('X-SDC-Assets'));
    }

    /**
     * An htmx partial, a component fetch()ed by the application.
     */
    public function testPartialGetsTheHeader(): void
    {
        $client = self::createClient(['compiler_passes' => [new RegisterTestLoggerPass()]]);
        $client->request('GET', '/page/fragment.html.twig', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $this->assertHeaderListsTheComponentAssets($client->getResponse());
        $this->assertSame([], $this->logger()->recordsOf(LogLevel::WARNING));
    }

    /**
     * Still reported in debug: loaded as a page, it gets none of its assets.
     */
    public function testPageWithoutThePlaceholderIsReportedAndGetsTheHeader(): void
    {
        $client = self::createClient(['compiler_passes' => [new RegisterTestLoggerPass()]]);
        $client->request('GET', '/page/no_placeholder.html.twig');

        self::assertResponseIsSuccessful();
        $this->assertHeaderListsTheComponentAssets($client->getResponse());
        $this->assertCount(1, $this->logger()->recordsOf(LogLevel::WARNING));
    }

    private function assertHeaderListsTheComponentAssets(Response $response): void
    {
        [$css, $js] = $this->componentAssets();

        $this->assertSame(
            \sprintf('{"v":1,"assets":[{"type":"css","href":"%s","attributes":{"data-turbo-track":"dynamic"}},{"type":"module","href":"%s"}]}', $css, $js),
            $response->headers->get('X-SDC-Assets'),
        );
    }

    /**
     * @return array{string, string} the public paths of the stylesheet and the script
     */
    private function componentAssets(): array
    {
        /** @var AssetMapperInterface $assetMapper */
        $assetMapper = self::getContainer()->get(AssetMapperInterface::class);
        $css = $assetMapper->getAsset('AutoDiscovery/AutoDiscoveryComponent.css')?->publicPath;
        $js = $assetMapper->getAsset('AutoDiscovery/AutoDiscoveryComponent.js')?->publicPath;

        $this->assertNotNull($css, 'The component stylesheet is not in the asset map.');
        $this->assertNotNull($js, 'The component script is not in the asset map.');

        return [$css, $js];
    }

    private function logger(): TestLogger
    {
        $logger = self::getContainer()->get('logger');
        $this->assertInstanceOf(TestLogger::class, $logger);

        return $logger;
    }
}
