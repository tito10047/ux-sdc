<?php

namespace Tito10047\UX\TwigComponentSdc\Tests\Integration;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\MappedAsset;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Tito10047\UX\TwigComponentSdc\Dto\ComponentAssetMap;
use Tito10047\UX\TwigComponentSdc\EventListener\AssetResponseListener;
use Twig\Environment;

class AutoDiscoveryIntegrationTest extends IntegrationTestCase
{
    public function testAutoDiscoveryCollectsAssetsAndTemplate(): void
    {
        self::bootKernel(['configs' => ['auto_discovery' => true]]);
        $container = self::getContainer();

        /** @var ComponentAssetMap $assetMap */
        $assetMap = $container->get(ComponentAssetMap::class);
        $map = $assetMap->getMap();

        $this->assertArrayHasKey('AutoDiscoveryComponent', $map);
        $this->assertArrayHasKey('AutoDiscoveryComponent_template', $map);

        $assets = $map['AutoDiscoveryComponent'];
        $paths = array_column($assets, 'path');
        $this->assertContains('AutoDiscoveryComponent.css', $paths);
        $this->assertContains('AutoDiscoveryComponent.js', $paths);

        $templatePath = $map['AutoDiscoveryComponent_template'];
        $this->assertStringEndsWith('AutoDiscoveryComponent.html.twig', $templatePath);
    }

    public function testAutoDiscoveryWorksWithSdcComponentAttribute(): void
    {
        self::bootKernel(['configs' => ['auto_discovery' => true]]);
        $container = self::getContainer();

        /** @var ComponentAssetMap $assetMap */
        $assetMap = $container->get(ComponentAssetMap::class);
        $map = $assetMap->getMap();

        $this->assertArrayHasKey('SdcAutoDiscoveryComponent', $map);
        $this->assertArrayHasKey('SdcAutoDiscoveryComponent_template', $map);

        $assets = $map['SdcAutoDiscoveryComponent'];
        $paths = array_column($assets, 'path');
        $this->assertContains('SdcAutoDiscoveryComponent.css', $paths);
        $this->assertContains('SdcAutoDiscoveryComponent.js', $paths);

        $templatePath = $map['SdcAutoDiscoveryComponent_template'];
        $this->assertStringEndsWith('SdcAutoDiscoveryComponent.html.twig', $templatePath);
    }

    public function testAutoDiscoveryFullRenderCycle(): void
    {
        $kernel = self::bootKernel(['configs' => ['auto_discovery' => true]]);
        $container = self::getContainer();

        $assetMapper = $this->createMock(AssetMapperInterface::class);
        $assetMapper->method('getAsset')
            ->willReturnCallback(function ($path) {
                return new MappedAsset($path, publicPath: '/assets/'.$path);
            });

        $container->set(AssetMapperInterface::class, $assetMapper);

        /** @var Environment $twig */
        $twig = $container->get(Environment::class);

        $loader = $twig->getLoader();
        if ($loader instanceof \Twig\Loader\FilesystemLoader) {
            $loader->addPath(realpath(__DIR__ . '/Fixtures/Component'));
        }

        // Render template with component that should be auto-discovered
        $html = $twig->render('auto_discovery.html.twig');

        // Verify that the auto-discovered template was used
        $this->assertStringContainsString('data-testid="auto-discovery-component"', $html);
        $this->assertStringContainsString('Auto Discovery Component Content', $html);

        // Simulate Kernel Response event
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

        // Verify assets are injected (based on auto-discovery filenames)
        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/AutoDiscoveryComponent.css">', $finalHtml);
        $this->assertStringContainsString('<script src="/assets/AutoDiscoveryComponent.js"></script>', $finalHtml);
    }
}
