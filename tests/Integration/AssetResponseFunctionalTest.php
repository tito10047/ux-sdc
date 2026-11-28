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

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\WebLink\EventListener\AddLinkHeaderListener;
use Tito10047\UX\Sdc\EventListener\AssetResponseListener;

/**
 * The whole pipeline, through the kernel: a page renders a component, the
 * response gets its tags in place of the placeholder and the matching preload
 * `Link` header from WebLink.
 */
final class AssetResponseFunctionalTest extends FunctionalTestCase
{
    private const PAGE = '/page/auto_discovery.html.twig';
    private const WEB_LINK_LISTENER = 'web_link.add_link_header_listener';

    public function testPageGetsComponentAssetsAndPreloadLinkHeader(): void
    {
        $client = self::createClient();
        $client->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        $this->assertComponentAssetsDelivered((string) $client->getResponse()->getContent(), $client->getResponse()->headers->get('Link'));
    }

    /**
     * Ways the order of the two listeners could change without this bundle doing
     * anything: WebLink registered like any other listener, which at an equal
     * priority puts it first (FrameworkBundle defines it before this bundle loads),
     * or WebLink — or the application — giving it a priority of its own.
     *
     * @return iterable<string, array{int}>
     */
    public static function provideWebLinkPriorities(): iterable
    {
        yield 'WebLink as a plain listener at the default priority' => [0];
        yield 'WebLink with a positive priority' => [32];
    }

    #[DataProvider('provideWebLinkPriorities')]
    public function testLinkHeaderDoesNotDependOnListenerRegistration(int $webLinkPriority): void
    {
        $client = self::createClient(['compiler_passes' => [
            new RegisterAsListenerPass(self::WEB_LINK_LISTENER, KernelEvents::RESPONSE, 'onKernelResponse', $webLinkPriority),
        ]]);
        $client->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        $this->assertComponentAssetsDelivered((string) $client->getResponse()->getContent(), $client->getResponse()->headers->get('Link'));
    }

    /**
     * What `debug:event-dispatcher kernel.response` shows.
     */
    public function testListenerRunsBeforeWebLink(): void
    {
        self::bootKernel();

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');

        $order = [];
        foreach ($dispatcher->getListeners(KernelEvents::RESPONSE) as $listener) {
            if (\is_array($listener) && \is_object($listener[0])) {
                $order[] = $listener[0]::class;
            }
        }

        $sdc = array_search(AssetResponseListener::class, $order, true);
        $webLink = array_search(AddLinkHeaderListener::class, $order, true);

        $this->assertIsInt($sdc, 'AssetResponseListener is not registered on kernel.response.');
        $this->assertIsInt($webLink, 'AddLinkHeaderListener is not registered on kernel.response.');
        $this->assertLessThan($webLink, $sdc);
        $this->assertGreaterThan(0, $dispatcher->getListenerPriority(KernelEvents::RESPONSE, [self::getContainer()->get(AssetResponseListener::class), 'onKernelResponse']));
    }

    private function assertComponentAssetsDelivered(string $html, ?string $linkHeader): void
    {
        /** @var AssetMapperInterface $assetMapper */
        $assetMapper = self::getContainer()->get(AssetMapperInterface::class);
        $css = $assetMapper->getAsset('AutoDiscovery/AutoDiscoveryComponent.css')?->publicPath;
        $js = $assetMapper->getAsset('AutoDiscovery/AutoDiscoveryComponent.js')?->publicPath;

        $this->assertNotNull($css, 'The component stylesheet is not in the asset map.');
        $this->assertNotNull($js, 'The component script is not in the asset map.');

        $this->assertStringContainsString('data-testid="auto-discovery-component"', $html);
        $this->assertStringNotContainsString('__UX_TWIG_COMPONENT_ASSETS__', $html);
        $this->assertSame(1, substr_count($html, sprintf('<link rel="stylesheet" href="%s" data-turbo-track="dynamic">', $css)));
        $this->assertSame(1, substr_count($html, sprintf('<script src="%s" type="module"></script>', $js)));

        // The script is a module, which is not preloaded (see ModuleScriptFunctionalTest).
        $this->assertSame(sprintf('<%s>; rel="preload"; as="style"', $css), $linkHeader);
    }
}
