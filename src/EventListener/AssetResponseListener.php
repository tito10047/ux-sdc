<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Tito10047\UX\Sdc\Service\AssetRegistry;
use Symfony\Component\WebLink\GenericLinkProvider;
use Symfony\Component\WebLink\Link;

final class AssetResponseListener
{
    public function __construct(
        private AssetRegistry $assetRegistry,
        private AssetMapperInterface $assetMapper,
        private string $placeholder = '<!-- __UX_TWIG_COMPONENT_ASSETS__ -->'
    ) {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();

        if ($this->isLiveComponentRequest($event)) {
            $assets = $this->assetRegistry->getSortedAssets();
            if (empty($assets)) {
                return;
            }

            $css = [];
            $js = [];

            foreach ($assets as $asset) {
                $mappedAsset = $this->assetMapper->getAsset($asset['path']);
                $path = $mappedAsset ? $mappedAsset->publicPath : $asset['path'];

                if ($asset['type'] === 'css' || ('' === $asset['type'] && str_ends_with($path, '.css'))) {
                    $css[] = $path;
                } elseif ($asset['type'] === 'js' || ('' === $asset['type'] && str_ends_with($path, '.js'))) {
                    $js[] = $path;
                }
            }

            if ($css) {
                $response->headers->set('X-SDC-Assets-CSS', implode(',', array_unique($css)));
            }
            if ($js) {
                $response->headers->set('X-SDC-Assets-JS', implode(',', array_unique($js)));
            }

            return;
        }

        if (!$event->isMainRequest()) {
            return;
        }

        $content = $response->getContent();

        if (false === $content || !str_contains($content, $this->placeholder)) {
            return;
        }

        $assets = $this->assetRegistry->getSortedAssets();
        if (empty($assets)) {
            $response->setContent(str_replace($this->placeholder, '', $content));
            return;
        }

        $html = '';
        $links = [];

        foreach ($assets as $asset) {
            $mappedAsset = $this->assetMapper->getAsset($asset['path']);
            $path = $mappedAsset ? $mappedAsset->publicPath : $asset['path'];

            $attributes = '';
            foreach ($asset['attributes'] as $name => $value) {
                $attributes .= sprintf(' %s="%s"', $name, htmlspecialchars((string) $value, ENT_QUOTES));
            }

            if ($asset['type'] === 'css' || ('' === $asset['type'] && str_ends_with($path, '.css'))) {
                $html .= sprintf('<link rel="stylesheet" href="%s"%s>' . "\n", $path, $attributes);
                $links[] = (new Link('preload', $path))->withAttribute('as', 'style');
            } elseif ($asset['type'] === 'js' || ('' === $asset['type'] && str_ends_with($path, '.js'))) {
                $html .= sprintf('<script src="%s"%s></script>' . "\n", $path, $attributes);
                $links[] = (new Link('preload', $path))->withAttribute('as', 'script');
            }
        }

        $response->setContent(str_replace($this->placeholder, $html, $content));

        if (!empty($links)) {
            $linkProvider = $event->getRequest()->attributes->get('_links', new GenericLinkProvider());
            foreach ($links as $link) {
                $linkProvider = $linkProvider->withLink($link);
            }
            $event->getRequest()->attributes->set('_links', $linkProvider);
        }
    }

    private function isLiveComponentRequest(ResponseEvent $event): bool
    {
        $request = $event->getRequest();

        return 'application/vnd.live-component+html' === $request->headers->get('Accept')
            || 'application/vnd.live-component+html' === $event->getResponse()->headers->get('Content-Type');
    }
}
