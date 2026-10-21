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
use Symfony\Component\WebLink\GenericLinkProvider;
use Symfony\Component\WebLink\Link;
use Tito10047\UX\Sdc\Csp\NonceProviderInterface;
use Tito10047\UX\Sdc\Response\AssetTagRenderer;
use Tito10047\UX\Sdc\Response\PageAssetInjector;
use Tito10047\UX\Sdc\Response\ResolvedAsset;
use Tito10047\UX\Sdc\Tests\TestLogger;

final class PageAssetInjectorTest extends TestCase
{
    private const PAGE = '<html><head>[[ASSETS]]</head><body>[[ASSETS]]</body></html>';

    public function testInjectsTheTagsAtTheFirstPlaceholderAndRemovesTheOthers(): void
    {
        $response = new Response(self::PAGE);

        $this->createInjector()->inject(new Request(), $response, self::PAGE, [
            new ResolvedAsset('/a.css', 'css'),
            new ResolvedAsset('/a.js', 'js'),
        ]);

        $this->assertSame(
            '<html><head><link rel="stylesheet" href="/a.css" data-turbo-track="dynamic">' . "\n" . '<script src="/a.js" type="module"></script>' . "\n" . '</head><body></body></html>',
            $response->getContent(),
        );
    }

    public function testPreloadsStylesheetsAndClassicScriptsOnly(): void
    {
        $request = new Request();
        $request->attributes->set('_links', (new GenericLinkProvider())->withLink(new Link('preload', '/app.css')));

        $this->createInjector()->inject($request, new Response(self::PAGE), self::PAGE, [
            new ResolvedAsset('/a.css', 'css'),
            new ResolvedAsset('/a.js', 'js'),
            new ResolvedAsset('/legacy.js', 'js', ['type' => false]),
        ]);

        $links = $request->attributes->get('_links');
        $this->assertInstanceOf(GenericLinkProvider::class, $links);
        $this->assertSame(
            ['/app.css' => null, '/a.css' => 'style', '/legacy.js' => 'script'],
            array_combine(
                array_map(static fn (Link $link): string => $link->getHref(), $links->getLinks()),
                array_map(static fn (Link $link): ?string => $link->getAttributes()['as'] ?? null, $links->getLinks()),
            ),
            'The links already there are kept.',
        );
    }

    public function testSetsNoLinksWithoutAssets(): void
    {
        $request = new Request();
        $response = new Response(self::PAGE);

        $this->createInjector()->inject($request, $response, self::PAGE, []);

        $this->assertSame('<html><head></head><body></body></html>', $response->getContent());
        $this->assertFalse($request->attributes->has('_links'));
    }

    public function testUpdatesAContentLengthSetBeforeTheInjection(): void
    {
        $response = new Response(self::PAGE, 200, ['Content-Length' => (string) \strlen(self::PAGE)]);

        $this->createInjector()->inject(new Request(), $response, self::PAGE, [new ResolvedAsset('/a.css', 'css')]);

        $this->assertSame((string) \strlen((string) $response->getContent()), $response->headers->get('Content-Length'));
    }

    public function testAsksOnlyForTheNoncesOfTheTagsInjected(): void
    {
        $nonceProvider = $this->createMock(NonceProviderInterface::class);
        $nonceProvider->expects($this->once())->method('getStyleNonce')->willReturn('style-n0nce');
        $nonceProvider->expects($this->never())->method('getScriptNonce');
        $response = new Response(self::PAGE);

        $this->createInjector($nonceProvider)->inject(new Request(), $response, self::PAGE, [
            new ResolvedAsset('/a.css', 'css'),
            new ResolvedAsset('/b.css', 'css'),
        ]);

        $this->assertStringContainsString('<link rel="stylesheet" href="/b.css" nonce="style-n0nce" data-turbo-track="dynamic">', (string) $response->getContent());
    }

    public function testWarnsInDebugWhenTheImportMapComesAfterModuleScripts(): void
    {
        $page = '<head>[[ASSETS]]<script type="importmap">{}</script></head>';
        $logger = new TestLogger();

        $this->createInjector(null, $logger)->inject(Request::create('/cart'), new Response($page), $page, [new ResolvedAsset('/a.js', 'js')]);

        $this->assertCount(1, $logger->records);
        $this->assertSame(['uri' => '/cart'], $logger->records[0]['context']);

        $logger->records = [];
        $this->createInjector(null, $logger)->inject(new Request(), new Response($page), $page, [new ResolvedAsset('/a.js', 'js', ['type' => false])]);
        $this->assertSame([], $logger->records, 'A classic script does not need the import map.');
    }

    private function createInjector(?NonceProviderInterface $nonceProvider = null, ?TestLogger $logger = null): PageAssetInjector
    {
        return new PageAssetInjector(new AssetTagRenderer(), '[[ASSETS]]', $nonceProvider, $logger, true);
    }
}
