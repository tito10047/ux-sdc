<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Response;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\WebLink\GenericLinkProvider;
use Symfony\Component\WebLink\Link;
use Tito10047\UX\Sdc\Csp\NonceProviderInterface;
use Tito10047\UX\Sdc\Service\AssetType;

/**
 * Puts the tags of the assets into a page, where its placeholder is, and the
 * preload links of the stylesheets and classic scripts into the `_links`
 * request attribute, which WebLink turns into the `Link` header.
 *
 * @internal
 */
final class PageAssetInjector
{
    /**
     * @param non-empty-string $placeholder
     * @param bool             $debug       an import map that comes after the tags is reported
     *                                      only in debug: it is a mistake in a template, and in
     *                                      production it would be logged on every request that
     *                                      renders the page
     */
    public function __construct(
        private readonly AssetTagRenderer $tagRenderer,
        private readonly string $placeholder = '<!-- __UX_TWIG_COMPONENT_ASSETS__ -->',
        private readonly ?NonceProviderInterface $nonceProvider = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $debug = false,
    ) {
    }

    /**
     * @param string              $content the content of the response, which holds the placeholder
     * @param list<ResolvedAsset> $assets
     */
    public function inject(Request $request, Response $response, string $content, array $assets): void
    {
        $types = array_column($assets, 'type');

        // Asked for only when a tag of that kind is injected: a provider such as
        // NelmioSecurityBundle adds a nonce to the policy as soon as it hands it out.
        $styleNonce = \in_array(AssetType::CSS, $types, true) ? $this->nonceProvider?->getStyleNonce() : null;
        $scriptNonce = \in_array(AssetType::JS, $types, true) ? $this->nonceProvider?->getScriptNonce() : null;

        $html = '';
        $links = [];
        $modules = false;

        foreach ($assets as $asset) {
            if ($asset->isStylesheet()) {
                $html .= $this->tagRenderer->render($asset, $styleNonce) . "\n";
                $links[] = (new Link('preload', $asset->path))->withAttribute('as', 'style');

                continue;
            }

            $html .= $this->tagRenderer->render($asset, $scriptNonce) . "\n";

            // A module script gets no preload header; it is fetched as soon as its
            // tag is parsed. `rel="preload"; as="script"` would fetch it in the
            // wrong mode for a module, so the browser downloads it twice.
            // `rel="modulepreload"` in a header starts loading modules before the
            // page's import map is parsed, after which a browser that does not merge
            // import maps ignores it (whatwg/html#9274, Firefox bug 1774414);
            // `importmap()` does not send one either.
            if ($asset->isModule()) {
                $modules = true;
            } else {
                $links[] = (new Link('preload', $asset->path))->withAttribute('as', 'script');
            }
        }

        if ($modules) {
            $this->reportImportMapAfterModules($request, $content);
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

        $this->addPreloadLinks($request, $links);
    }

    /**
     * @param list<Link> $links
     */
    private function addPreloadLinks(Request $request, array $links): void
    {
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
     * An import map has to come before every module script of the document: a
     * browser that does not merge import maps ignores one parsed after it has
     * started loading modules, and every bare import of the page
     * (`import '@hotwired/stimulus'`) — those of the application's own entrypoint
     * included — then fails to resolve.
     *
     * The tags go where the first placeholder is, so an import map found further
     * down the page is reported. A page without an import map is not: its modules
     * may only use relative imports.
     */
    private function reportImportMapAfterModules(Request $request, string $content): void
    {
        if (!$this->debug || null === $this->logger) {
            return;
        }

        if (1 !== preg_match('/<script\b[^>]*\stype\s*=\s*["\']?importmap\b/i', $content, $match, \PREG_OFFSET_CAPTURE)) {
            return;
        }

        if ($match[0][1] < strpos($content, $this->placeholder)) {
            return;
        }

        $this->logger->warning(
            'The page "{uri}" injects component scripts, which are ES modules, before its import map: an import map that comes after a module script is ignored by browsers that do not merge import maps, and the bare imports of the page then fail to resolve. Call {{ render_component_assets() }} after {{ importmap() }}.',
            ['uri' => $request->getRequestUri()]
        );
    }
}
