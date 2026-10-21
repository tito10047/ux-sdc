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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Tito10047\UX\Sdc\Csp\NonceProviderInterface;
use Tito10047\UX\Sdc\Service\AssetPath;
use Tito10047\UX\Sdc\Service\AssetRegistry;
use Symfony\Component\WebLink\GenericLinkProvider;
use Symfony\Component\WebLink\Link;

/**
 * Puts the assets of the components rendered for a response into that response.
 *
 * A page gets tags in place of the placeholder. A response the client inserts
 * only a part of — a Live Component re-render, a Turbo Frame or Stream, any
 * other HTML without the placeholder — lists them in the `X-SDC-Assets` header
 * instead, which the bundle's loader controller reads.
 *
 * Registered on `kernel.response` in `config/services.php`, where its priority
 * is explained.
 *
 * Scripts are ES modules (`type="module"`) unless the asset says otherwise:
 * AssetMapper serves JavaScript as modules, and a file with an `import` statement
 * is a syntax error when loaded as a classic script. An asset that sets the
 * `type` attribute itself keeps it, and `'type' => false` leaves the attribute
 * out, which loads a classic script.
 */
final class AssetResponseListener
{
    /**
     * Lists the assets of a response that gets no tags; see addAssetHeader().
     */
    public const HEADER = 'X-SDC-Assets';

    /**
     * The version of the header format, raised on any change a client of an
     * earlier version would misread.
     */
    public const HEADER_VERSION = 1;

    /**
     * Half of the smallest default buffer nginx reads all the response headers
     * of an upstream into (4k), the rest being for the other headers — a
     * Content-Security-Policy and a few cookies easily take 1–2 KB.
     */
    private const HEADER_SIZE_WARNING = 2048;

    private const LIVE_COMPONENT_MEDIA_TYPE = 'application/vnd.live-component+html';
    private const TURBO_STREAM_MEDIA_TYPE = 'text/vnd.turbo-stream.html';

    /**
     * @param non-empty-string $placeholder
     * @param bool             $debug       a page missing the placeholder, an asset path the
     *                                      asset map does not know and an asset header too long
     *                                      for a proxy are reported only in debug: they are
     *                                      mistakes in a template, an attribute or a fragment,
     *                                      and in production they would be logged on every
     *                                      request that renders the page
     */
    public function __construct(
        private AssetRegistry $assetRegistry,
        private AssetMapperInterface $assetMapper,
        private string $placeholder = '<!-- __UX_TWIG_COMPONENT_ASSETS__ -->',
        private ?LoggerInterface $logger = null,
        private bool $debug = false,
        private ?NonceProviderInterface $nonceProvider = null,
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

        if (false === $content || !$this->isHtmlResponse($request, $response)) {
            return;
        }

        // The client keeps only a part of a fragment response: Live Components
        // morph the component, Turbo the frame or the streams. Tags in its
        // <head> would be dropped, so the assets travel in a header even when
        // the response is a whole page with the placeholder, as a Turbo Frame
        // response often is.
        if ($this->isFragmentResponse($request, $response)) {
            $this->addAssetHeader($request, $response);

            return;
        }

        if (str_contains($content, $this->placeholder)) {
            $this->injectAssets($request, $response, $content);

            return;
        }

        // Any other response without the placeholder is a partial a script
        // inserts into the page (htmx, fetch()) — or a page that forgot the
        // placeholder, which is reported. Either way the header is all it can
        // carry the assets in.
        $this->reportMissingPlaceholder($request, $content);
        $this->addAssetHeader($request, $response);
    }

    /**
     * Lists the assets in the `X-SDC-Assets` header, for the loader controller
     * (or any other client) to add the ones the page does not have yet.
     *
     * The value is JSON, ASCII-only (non-ASCII characters are `\u` escapes):
     *
     *     {"v":1,"assets":[{"type":"css","href":"/assets/a-1b2c.css","attributes":{"data-turbo-track":"dynamic"}},{"type":"module","href":"/assets/a-3d4e.js"}]}
     *
     * in the order of the tags of a full page. `type` is `css` (a stylesheet),
     * `module` (a module script) or `script` (a classic script); `attributes`,
     * left out when there are none, are those the tag of a full page gets after
     * `rel`/`href`/`src` and the `type="module"` of a module — a string value,
     * or `true` for a bare attribute. The CSP nonce is not among them: the policy
     * that applies is the one the page was loaded with, so the client uses the
     * nonce of the page, unless the asset sets its own (`"nonce": "…"`) or opts
     * out of it (`"nonce": false`).
     */
    private function addAssetHeader(Request $request, Response $response): void
    {
        $entries = [];

        foreach ($this->resolveAssets() as $asset) {
            $entry = $this->headerEntry($asset);
            $entries[$entry['type'] . ' ' . $entry['href']] ??= $entry;
        }

        if ([] === $entries) {
            return;
        }

        // Without JSON_UNESCAPED_UNICODE the value is ASCII, as a header value
        // has to be; a path that is not valid UTF-8 cannot fail the response.
        $value = json_encode(
            ['v' => self::HEADER_VERSION, 'assets' => array_values($entries)],
            \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
        );

        $response->headers->set(self::HEADER, $value);

        if ($this->debug && \strlen($value) > self::HEADER_SIZE_WARNING) {
            $this->logger?->warning(
                'The "{header}" header of "{uri}" is {size} bytes long, for {count} component assets. A reverse proxy fails a response whose headers do not fit its buffer — nginx in front of PHP-FPM answers 502 "upstream sent too big header" once they exceed fastcgi_buffer_size (4k or 8k by default). Raise that buffer (fastcgi_buffer_size/proxy_buffer_size 16k), or render fewer components with assets per fragment.',
                ['header' => self::HEADER, 'uri' => $request->getRequestUri(), 'size' => \strlen($value), 'count' => \count($entries)]
            );
        }
    }

    /**
     * @param array{path: string, type: 'css'|'js', attributes: array<string, mixed>} $asset
     *
     * @return array{type: 'css'|'module'|'script', href: string, attributes?: array<string, string|bool>}
     */
    private function headerEntry(array $asset): array
    {
        if ('css' === $asset['type']) {
            $type = 'css';
            $attributes = $this->styleAttributes($asset['attributes'], null);
        } else {
            $attributes = $this->scriptAttributes($asset['attributes'], null);
            $type = $this->isModule($attributes) ? 'module' : 'script';
        }

        if ('module' === $type) {
            unset($attributes['type']);
        }

        // The nonce of the page goes where the bundle's default stands; only a
        // nonce the asset sets itself — or turns off — travels.
        if (!\array_key_exists('nonce', $asset['attributes'])) {
            unset($attributes['nonce']);
        }

        $values = [];
        foreach ($attributes as $name => $value) {
            if ('nonce' === $name && (false === $value || null === $value)) {
                $values[$name] = false;
            } elseif (false !== $value && null !== $value) {
                $values[$name] = true === $value ? true : (string) $value;
            }
        }

        $entry = ['type' => $type, 'href' => $asset['path']];
        if ([] !== $values) {
            $entry['attributes'] = $values;
        }

        return $entry;
    }

    private function injectAssets(Request $request, Response $response, string $content): void
    {
        $assets = $this->resolveAssets();
        $types = array_column($assets, 'type');

        // Asked for only when a tag of that kind is injected: a provider such as
        // NelmioSecurityBundle adds a nonce to the policy as soon as it hands it out.
        $styleNonce = \in_array('css', $types, true) ? $this->nonceProvider?->getStyleNonce() : null;
        $scriptNonce = \in_array('js', $types, true) ? $this->nonceProvider?->getScriptNonce() : null;

        $html = '';
        $links = [];
        $modules = false;

        foreach ($assets as $asset) {
            $path = htmlspecialchars($asset['path'], ENT_QUOTES);

            if ('css' === $asset['type']) {
                $attributes = $this->styleAttributes($asset['attributes'], $styleNonce);
                $html .= sprintf('<link rel="stylesheet" href="%s"%s>' . "\n", $path, $this->renderAttributes($attributes));
                $links[] = (new Link('preload', $asset['path']))->withAttribute('as', 'style');

                continue;
            }

            $attributes = $this->scriptAttributes($asset['attributes'], $scriptNonce);
            $html .= sprintf('<script src="%s"%s></script>' . "\n", $path, $this->renderAttributes($attributes));

            // A module script gets no preload header; it is fetched as soon as its
            // tag is parsed. `rel="preload"; as="script"` would fetch it in the
            // wrong mode for a module, so the browser downloads it twice.
            // `rel="modulepreload"` in a header starts loading modules before the
            // page's import map is parsed, after which a browser that does not merge
            // import maps ignores it (whatwg/html#9274, Firefox bug 1774414);
            // `importmap()` does not send one either.
            if ($this->isModule($attributes)) {
                $modules = true;
            } else {
                $links[] = (new Link('preload', $asset['path']))->withAttribute('as', 'script');
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
            $path = $this->assetMapper->getAsset($asset['path'])->publicPath ?? $this->passThrough($asset['path']);

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
     * A path the asset map does not know is linked as it is. That is what a
     * URL is for; anything else was meant as a logical path — a typo in
     * #[Asset], or a file AssetMapper does not serve — and will not load.
     */
    private function passThrough(string $path): string
    {
        if ($this->debug && !AssetPath::isUrl($path)) {
            $this->logger?->warning(
                'The component asset "{path}" is not in the asset map, so it is linked as it is and will most likely not load. Reference it by its AssetMapper logical path (see "bin/console debug:asset-map" and "bin/console debug:sdc"), or by a URL.',
                ['path' => $path]
            );
        }

        return $path;
    }

    /**
     * The attributes of a stylesheet link: the nonce and
     * `data-turbo-track="dynamic"` first, then those of the asset, which override
     * either.
     *
     * Turbo Drive adds the stylesheets of the next page to <head> and, on
     * navigation, removes those marked `dynamic` that the next page does not
     * have, so the CSS of a component stays on the pages that render it, as it
     * does without Turbo. Without the mark every stylesheet a visit ever loaded
     * stays — a component stylesheet with a global rule would go on applying.
     * Turbo leaves scripts in place whatever their mark, so they get none.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private function styleAttributes(array $attributes, ?string $nonce): array
    {
        return array_merge(['nonce' => $nonce, 'data-turbo-track' => 'dynamic'], $attributes);
    }

    /**
     * The attributes of a script tag: `type="module"` and the nonce first, then
     * those of the asset, which override either. `'type' => false` therefore
     * renders a classic script, and `'nonce' => false` a tag without the nonce.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private function scriptAttributes(array $attributes, ?string $nonce): array
    {
        return array_merge(['type' => 'module', 'nonce' => $nonce], $attributes);
    }

    /**
     * @param array<string, mixed> $attributes as returned by scriptAttributes()
     */
    private function isModule(array $attributes): bool
    {
        return \is_string($attributes['type']) && 'module' === strtolower(trim($attributes['type']));
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
     * responses: the Content-Type mentions HTML. Live Component
     * (`application/vnd.live-component+html`) and Turbo Stream
     * (`text/vnd.turbo-stream.html`) responses are HTML too.
     */
    private function isHtmlResponse(Request $request, Response $response): bool
    {
        return str_contains($this->mediaType($request, $response) ?? '', 'html');
    }

    /**
     * A response the client takes apart instead of loading it as a page:
     *
     *  - a Live Component re-render: the request accepts
     *    `application/vnd.live-component+html`, or the response is of that type;
     *  - a Turbo Frame navigation or form submission: the request has the
     *    `Turbo-Frame` header, which Turbo sends only for those;
     *  - Turbo Streams: the response is `text/vnd.turbo-stream.html`. Not the
     *    `Accept` header — Turbo accepts streams with every form submission,
     *    and the response may as well be a whole page.
     */
    private function isFragmentResponse(Request $request, Response $response): bool
    {
        $mediaType = strtolower(trim(explode(';', $this->mediaType($request, $response) ?? '')[0]));

        return self::LIVE_COMPONENT_MEDIA_TYPE === $mediaType
            || self::TURBO_STREAM_MEDIA_TYPE === $mediaType
            || $request->headers->has('Turbo-Frame')
            || \in_array(self::LIVE_COMPONENT_MEDIA_TYPE, $request->getAcceptableContentTypes(), true);
    }

    /**
     * The Content-Type of the response, or, when the controller did not set one,
     * the one `Response::prepare()` will derive from the request format — after
     * this listener has run. A controller answering Turbo Streams typically sets
     * only the format (`$request->setRequestFormat(TurboBundle::STREAM_FORMAT)`).
     */
    private function mediaType(Request $request, Response $response): ?string
    {
        return $response->headers->get('Content-Type') ?? $request->getMimeType($request->getRequestFormat() ?? 'html');
    }
}
