<?php

namespace Tito10047\UX\TwigComponentSdc\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Tito10047\UX\TwigComponentSdc\Service\AssetRegistry;
use Symfony\Component\WebLink\GenericLinkProvider;
use Symfony\Component\WebLink\Link;

final class AssetResponseListener
{
    public function __construct(
        private AssetRegistry $assetRegistry,
        private AssetMapperInterface $assetMapper,
        private string $placeholder = '<!-- __UX_TWIG_COMPONENT_ASSETS__ -->',
        private ?string $cachePath = null,
        private bool $debug = false
    ) {
    }

    public function getPublicPath(string $logicalPath): ?string
    {
        if (null !== $this->cachePath && file_exists($this->cachePath)) {
            $map = require $this->cachePath;
            if (isset($map[$logicalPath])) {
                return $map[$logicalPath];
            }
        }

        // Len v DEV: Ak mapa neexistuje, zavolaj AssetMapper (pomalšie, ale funkčné pri vývoji)
        if ($this->debug) {
            return $this->assetMapper->getAsset($logicalPath)?->publicPath;
        }

        return null;
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $content = $response->getContent();

        if (false === $content || !str_contains($content, $this->placeholder)) {
            return;
        }

        $assets = $this->assetRegistry->getSortedAssets();
        $html = '';
        $links = [];

        foreach ($assets as $asset) {
            $path = $this->getPublicPath($asset['path']) ?? $asset['path'];

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
}
