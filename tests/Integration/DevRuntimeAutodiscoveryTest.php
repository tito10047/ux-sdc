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

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\MappedAsset;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Tito10047\UX\Sdc\EventListener\AssetResponseListener;
use Tito10047\UX\Sdc\Service\AssetRegistry;
use Twig\Environment;

class DevRuntimeAutodiscoveryTest extends IntegrationTestCase
{
    public function testRuntimeAutodiscoveryWithoutMetadataCache(): void
    {
        $kernel = self::bootKernel(['configs' => ['auto_discovery' => true, 'metadata_cache' => false]]);
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

        // Render template with component
        // Without the metadata cache, DevComponentRenderListener resolves the
        // assets on PreRenderEvent.
        $html = $twig->render('auto_discovery.html.twig');

        // Overíme, že template bola nájdená (inak by twig vyhodil chybu ak by ju nenašiel cez alias)
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

        // Overíme, že assets boli nájdené a vložené
        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/AutoDiscovery/AutoDiscoveryComponent.css">', $finalHtml);
        $this->assertStringContainsString('<script src="/assets/AutoDiscovery/AutoDiscoveryComponent.js"></script>', $finalHtml);
    }

    public function testRuntimeAutodiscoveryIsDisabledWhenConfigDisabled(): void
    {
        $kernel = self::bootKernel(['configs' => ['auto_discovery' => false, 'metadata_cache' => false]]);
        $container = self::getContainer();

        $assetMapper = $this->createMock(AssetMapperInterface::class);
        $container->set(AssetMapperInterface::class, $assetMapper);

        /** @var Environment $twig */
        $twig = $container->get(Environment::class);

        $loader = $twig->getLoader();
        if ($loader instanceof \Twig\Loader\FilesystemLoader) {
            $loader->addPath(realpath(__DIR__ . '/Fixtures/Component'));
        }

        // Použijeme SdcComponent, ktorý má template definovanú v atribúte
        $html = $twig->render('base.html.twig');

        /** @var AssetRegistry $assetRegistry */
        $assetRegistry = $container->get(AssetRegistry::class);

        // SdcComponent má v atribúte definované css a js, takže by sa mali pridať
        // aj keď je auto_discovery vypnuté.
        $this->assertNotEmpty($assetRegistry->getSortedAssets());
    }
}
