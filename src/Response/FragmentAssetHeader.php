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

/**
 * Lists the assets of a response that gets no tags in the `X-SDC-Assets`
 * header, for the loader controller (or any other client) to add the ones the
 * page does not have yet.
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
 *
 * @internal
 */
final class FragmentAssetHeader
{
    public const NAME = 'X-SDC-Assets';

    /**
     * The version of the header format, raised on any change a client of an
     * earlier version would misread.
     */
    public const VERSION = 1;

    /**
     * Half of the smallest default buffer nginx reads all the response headers
     * of an upstream into (4k), the rest being for the other headers — a
     * Content-Security-Policy and a few cookies easily take 1–2 KB.
     */
    private const SIZE_WARNING = 2048;

    /**
     * @param bool $debug a header too long for a proxy is reported only in debug: it
     *                    depends on the components a fragment renders, and in
     *                    production it would be logged on every request for it
     */
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $debug = false,
    ) {
    }

    /**
     * Sets the header on the response; a response without assets gets none.
     *
     * @param list<ResolvedAsset> $assets
     */
    public function addTo(Request $request, Response $response, array $assets): void
    {
        $entries = [];

        foreach ($assets as $asset) {
            $entry = $this->entry($asset);
            $entries[$entry['type'] . ' ' . $entry['href']] ??= $entry;
        }

        if ([] === $entries) {
            return;
        }

        // Without JSON_UNESCAPED_UNICODE the value is ASCII, as a header value
        // has to be; a path that is not valid UTF-8 cannot fail the response.
        $value = json_encode(
            ['v' => self::VERSION, 'assets' => array_values($entries)],
            \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
        );

        $response->headers->set(self::NAME, $value);

        if ($this->debug && \strlen($value) > self::SIZE_WARNING) {
            $this->logger?->warning(
                'The "{header}" header of "{uri}" is {size} bytes long, for {count} component assets. A reverse proxy fails a response whose headers do not fit its buffer — nginx in front of PHP-FPM answers 502 "upstream sent too big header" once they exceed fastcgi_buffer_size (4k or 8k by default). Raise that buffer (fastcgi_buffer_size/proxy_buffer_size 16k), or render fewer components with assets per fragment.',
                ['header' => self::NAME, 'uri' => $request->getRequestUri(), 'size' => \strlen($value), 'count' => \count($entries)]
            );
        }
    }

    /**
     * @return array{type: 'css'|'module'|'script', href: string, attributes?: array<string, string|bool>}
     */
    private function entry(ResolvedAsset $asset): array
    {
        $attributes = $asset->tagAttributes(null);

        if ($asset->isStylesheet()) {
            $type = 'css';
        } elseif ($asset->isModule()) {
            $type = 'module';
            unset($attributes['type']);
        } else {
            $type = 'script';
        }

        // The nonce of the page goes where the bundle's default stands; only a
        // nonce the asset sets itself — or turns off — travels.
        if (!\array_key_exists('nonce', $asset->attributes)) {
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

        $entry = ['type' => $type, 'href' => $asset->path];
        if ([] !== $values) {
            $entry['attributes'] = $values;
        }

        return $entry;
    }
}
