<?php

namespace Tito10047\UX\Sdc\Tests\Integration;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\MappedAsset;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\EventListener\AssetResponseListener;
use Twig\Environment;

class AssetIntegrationTest extends IntegrationTestCase
{
    public function testCompilerPassCollectsAssets(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var SdcMetadataRegistry $metadataRegistry */
        $metadataRegistry = $container->get(SdcMetadataRegistry::class);

        $this->assertNotNull($metadataRegistry->getMetadata('TestComponent'));
        $assets = $metadataRegistry->getMetadata('TestComponent');
        $this->assertCount(2, $assets);

        $paths = array_column($assets, 'path');
        $this->assertContains('css/test.css', $paths);
        $this->assertContains('js/test.js', $paths);
    }

    public function testFullRenderCycle(): void
    {
        // 1. Prepare Kernel and mock AssetMapper before any service is accessed
        $kernel = self::bootKernel(['configs' => []]);
        $container = self::getContainer();

        $assetMapper = $this->createMock(AssetMapperInterface::class);
        $assetMapper->method('getAsset')
            ->willReturnCallback(function ($path) {
                return new MappedAsset($path, publicPath: '/assets/'.$path);
            });

        $container->set(AssetMapperInterface::class, $assetMapper);

        /** @var Environment $twig */
        $twig = $container->get(Environment::class);

        // 2. Render template with component
        $html = $twig->render('base.html.twig');

        $this->assertStringContainsString('data-testid="test-component"', $html);
        $this->assertStringContainsString('<!-- __UX_TWIG_COMPONENT_ASSETS__ -->', $html);

        // 2. Simulate Kernel Response event
        $request = new Request();
        $response = new Response($html);
        $event = new ResponseEvent(
            $container->get('http_kernel'),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response
        );

        /** @var AssetResponseListener $listener */
        $listener = $container->get(AssetResponseListener::class);
        $listener->onKernelResponse($event);

        $finalHtml = $response->getContent();

        // 3. Verify assets are injected
        $this->assertStringNotContainsString('<!-- __UX_TWIG_COMPONENT_ASSETS__ -->', $finalHtml);
        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/css/test.css">', $finalHtml);
        $this->assertStringContainsString('<script src="/assets/js/test.js"></script>', $finalHtml);

        // 4. Verify Link headers (preload)
        $linkProvider = $request->attributes->get('_links');
        $this->assertNotNull($linkProvider);
        $links = iterator_to_array($linkProvider->getLinks());
        $this->assertCount(2, $links);

        $linkPaths = array_map(fn ($l) => $l->getHref(), $links);
        $this->assertContains('/assets/css/test.css', $linkPaths);
        $this->assertContains('/assets/js/test.js', $linkPaths);
    }
}
