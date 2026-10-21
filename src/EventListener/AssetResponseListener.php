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

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Tito10047\UX\Sdc\Response\AssetResolver;
use Tito10047\UX\Sdc\Response\FragmentAssetHeader;
use Tito10047\UX\Sdc\Response\PageAssetInjector;
use Tito10047\UX\Sdc\Response\ResolvedAsset;
use Tito10047\UX\Sdc\Response\ResponseClassifier;
use Tito10047\UX\Sdc\Service\AssetRegistry;

/**
 * Puts the assets of the components rendered for a response into that response.
 *
 * A page gets tags in place of the placeholder (PageAssetInjector). A response
 * the client inserts only a part of — a Live Component re-render, a Turbo Frame
 * or Stream, any other HTML without the placeholder — lists them in the
 * `X-SDC-Assets` header instead (FragmentAssetHeader), which the bundle's
 * loader controller reads.
 *
 * Registered on `kernel.response` in `config/services.php`, where its priority
 * is explained.
 */
final class AssetResponseListener
{
    /**
     * Lists the assets of a response that gets no tags; see FragmentAssetHeader.
     */
    public const HEADER = FragmentAssetHeader::NAME;

    /**
     * The version of the header format, raised on any change a client of an
     * earlier version would misread.
     */
    public const HEADER_VERSION = FragmentAssetHeader::VERSION;

    /**
     * @param non-empty-string $placeholder
     * @param bool             $debug       a page missing the placeholder is reported only in
     *                                      debug: it is a mistake in a template, and in
     *                                      production it would be logged on every request that
     *                                      renders the page
     */
    public function __construct(
        private readonly AssetRegistry $assetRegistry,
        private readonly AssetResolver $assetResolver,
        private readonly ResponseClassifier $responseClassifier,
        private readonly PageAssetInjector $pageAssetInjector,
        private readonly FragmentAssetHeader $fragmentAssetHeader,
        private readonly string $placeholder = '<!-- __UX_TWIG_COMPONENT_ASSETS__ -->',
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $debug = false,
    ) {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        // Components rendered in a sub-request land in the same registry and are
        // injected into the main response; a fragment never gets them itself.
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        // Streamed and binary responses have no content to look into (false).
        $content = $response->getContent();

        if (false === $content || !$this->responseClassifier->isHtml($request, $response)) {
            return;
        }

        // The client keeps only a part of a fragment response: Live Components
        // morph the component, Turbo the frame or the streams. Tags in its
        // <head> would be dropped, so the assets travel in a header even when
        // the response is a whole page with the placeholder, as a Turbo Frame
        // response often is.
        if ($this->responseClassifier->isFragment($request, $response)) {
            $this->fragmentAssetHeader->addTo($request, $response, $this->resolveAssets());

            return;
        }

        if (str_contains($content, $this->placeholder)) {
            $this->pageAssetInjector->inject($request, $response, $content, $this->resolveAssets());

            return;
        }

        // Any other response without the placeholder is a partial a script
        // inserts into the page (htmx, fetch()) — or a page that forgot the
        // placeholder, which is reported. Either way the header is all it can
        // carry the assets in.
        $this->reportMissingPlaceholder($request, $content);
        $this->fragmentAssetHeader->addTo($request, $response, $this->resolveAssets());
    }

    /**
     * @return list<ResolvedAsset>
     */
    private function resolveAssets(): array
    {
        return $this->assetResolver->resolve($this->assetRegistry->getSortedAssets());
    }

    /**
     * Components were rendered, but their assets have nowhere to go: the page
     * does not call render_component_assets().
     *
     * Only a full document is reported. A fragment — an htmx partial, a
     * component rendered alone — has no <head> of its own; its components rely
     * on the page it is inserted into, which gets their assets from the header,
     * so it legitimately comes without the placeholder. Live Component and
     * Turbo responses never get here.
     */
    private function reportMissingPlaceholder(Request $request, string $content): void
    {
        if (!$this->debug || null === $this->logger || false === stripos($content, '</head>')) {
            return;
        }

        $assets = $this->assetRegistry->getSortedAssets();
        if ([] === $assets) {
            return;
        }

        $this->logger->warning(
            'The page "{uri}" renders components with assets ({assets}), but has no "{placeholder}" placeholder, so none of them is loaded. Call {{ render_component_assets() }} inside the <head> of the base layout.',
            [
                'uri' => $request->getRequestUri(),
                'assets' => implode(', ', array_unique(array_column($assets, 'path'))),
                'placeholder' => $this->placeholder,
            ]
        );
    }
}
