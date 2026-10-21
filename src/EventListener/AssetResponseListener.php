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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Tito10047\UX\Sdc\Service\AssetRegistry;
use Symfony\Component\WebLink\GenericLinkProvider;
use Symfony\Component\WebLink\Link;

/**
 * Puts the assets of the components rendered for a response into that response.
 *
 * Registered on `kernel.response` in `config/services.php`, where its priority
 * is explained.
 */
final class AssetResponseListener
{
    /**
     * @param non-empty-string $placeholder
     */
    public function __construct(
        private AssetRegistry $assetRegistry,
        private AssetMapperInterface $assetMapper,
        private string $placeholder = '<!-- __UX_TWIG_COMPONENT_ASSETS__ -->'
    ) {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        // Components rendered in a sub-request land in the same registry and are
        // injected into the main response; a fragment never gets them itself.
        if (!$event->isMainRequest()) {
            return;
        }

        if ($this->isLiveComponentRequest($event)) {
            $this->addAssetHeaders($event->getResponse());

            return;
        }

        $this->injectAssets($event->getRequest(), $event->getResponse());
    }

    private function addAssetHeaders(Response $response): void
    {
        $css = [];
        $js = [];

        foreach ($this->resolveAssets() as $asset) {
            if ('css' === $asset['type']) {
                $css[] = $asset['path'];
            } else {
                $js[] = $asset['path'];
            }
        }

        if ($css) {
            $response->headers->set('X-SDC-Assets-CSS', implode(',', array_unique($css)));
        }
        if ($js) {
            $response->headers->set('X-SDC-Assets-JS', implode(',', array_unique($js)));
        }
    }

    private function injectAssets(Request $request, Response $response): void
    {
        // Streamed and binary responses have no content to inject into (false).
        $content = $response->getContent();

        if (false === $content || !str_contains($content, $this->placeholder) || !$this->isHtmlResponse($request, $response)) {
            return;
        }

        $html = '';
        $links = [];

        foreach ($this->resolveAssets() as $asset) {
            $path = htmlspecialchars($asset['path'], ENT_QUOTES);
            $attributes = $this->renderAttributes($asset['attributes']);

            if ('css' === $asset['type']) {
                $html .= sprintf('<link rel="stylesheet" href="%s"%s>' . "\n", $path, $attributes);
                $links[] = (new Link('preload', $asset['path']))->withAttribute('as', 'style');
            } else {
                $html .= sprintf('<script src="%s"%s></script>' . "\n", $path, $attributes);
                $links[] = (new Link('preload', $asset['path']))->withAttribute('as', 'script');
            }
        }

        // The tags go where the first placeholder is. Any further placeholder (a
        // layout and a partial both calling render_component_assets()) is only
        // removed: injecting there too would load every stylesheet twice and run
        // every script twice.
        $parts = explode($this->placeholder, $content);
        $content = array_shift($parts) . $html . implode('', $parts);

        $response->setContent($content);

        // A Content-Length set by the controller describes the content before the
        // injection; left alone, clients would truncate the page.
        if ($response->headers->has('Content-Length')) {
            $response->headers->set('Content-Length', (string) \strlen($content));
        }

        if ([] === $links) {
            return;
        }

        // Read by WebLink's AddLinkHeaderListener, which turns it into the `Link` header.
        $linkProvider = $request->attributes->get('_links', new GenericLinkProvider());
        foreach ($links as $link) {
            $linkProvider = $linkProvider->withLink($link);
        }
        $request->attributes->set('_links', $linkProvider);
    }

    /**
     * The assets collected for this response, with their public paths and their
     * type narrowed to `css` or `js`. Assets of any other type are left out.
     *
     * @return list<array{path: string, type: 'css'|'js', attributes: array<string, mixed>}>
     */
    private function resolveAssets(): array
    {
        $resolved = [];

        foreach ($this->assetRegistry->getSortedAssets() as $asset) {
            $path = $this->assetMapper->getAsset($asset['path'])->publicPath ?? $asset['path'];

            $type = match (true) {
                'css' === $asset['type'], '' === $asset['type'] && str_ends_with($path, '.css') => 'css',
                'js' === $asset['type'], '' === $asset['type'] && str_ends_with($path, '.js') => 'js',
                default => null,
            };

            if (null !== $type) {
                $resolved[] = ['path' => $path, 'type' => $type, 'attributes' => $asset['attributes']];
            }
        }

        return $resolved;
    }

    /**
     * Same convention as Twig component attributes: `true` renders the bare
     * attribute name, `false` and `null` leave the attribute out.
     *
     * @param array<string, mixed> $attributes
     */
    private function renderAttributes(array $attributes): string
    {
        $html = '';

        foreach ($attributes as $name => $value) {
            if (false === $value || null === $value) {
                continue;
            }

            $html .= true === $value
                ? ' ' . $name
                : sprintf(' %s="%s"', $name, htmlspecialchars((string) $value, ENT_QUOTES));
        }

        return $html;
    }

    /**
     * Mirrors the check of the web profiler toolbar, which injects into the same
     * responses. A response without a Content-Type is HTML unless the request
     * format says otherwise: that is what `Response::prepare()` will send.
     */
    private function isHtmlResponse(Request $request, Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type');

        if (null !== $contentType) {
            return str_contains($contentType, 'html');
        }

        return 'html' === $request->getRequestFormat();
    }

    private function isLiveComponentRequest(ResponseEvent $event): bool
    {
        $request = $event->getRequest();

        return 'application/vnd.live-component+html' === $request->headers->get('Accept')
            || 'application/vnd.live-component+html' === $event->getResponse()->headers->get('Content-Type');
    }
}
