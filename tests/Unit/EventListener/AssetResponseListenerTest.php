<?php

namespace Tito10047\UX\Sdc\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Tito10047\UX\Sdc\EventListener\AssetResponseListener;
use Tito10047\UX\Sdc\Service\AssetRegistry;

final class AssetResponseListenerTest extends TestCase
{
    public function testOnKernelResponseReplacesPlaceholder(): void
    {
        $registry = new AssetRegistry();
        $registry->addAsset('style.css', 'css');

        $assetMapper = $this->createMock(AssetMapperInterface::class);
        $assetMapper->method('getAsset')->willReturn(null);

        $listener = new AssetResponseListener($registry, $assetMapper, '[[ASSETS]]');

        $response = new Response('<html><head>[[ASSETS]]</head><body></body></html>');
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::MAIN_REQUEST,
            $response
        );

        $listener->onKernelResponse($event);

        $this->assertStringContainsString('<link rel="stylesheet" href="style.css">', $response->getContent());
        $this->assertStringNotContainsString('[[ASSETS]]', $response->getContent());
    }
}
