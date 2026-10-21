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

use Tito10047\UX\Sdc\Attribute\Asset;
use Tito10047\UX\Sdc\Service\AssetType;

/**
 * A component asset as the response links to it: its public path, its type,
 * and the attributes its tag gets.
 *
 * Scripts are ES modules (`type="module"`) unless the asset says otherwise:
 * AssetMapper serves JavaScript as modules, and a file with an `import` statement
 * is a syntax error when loaded as a classic script. An asset that sets the
 * `type` attribute itself keeps it, and `'type' => false` leaves the attribute
 * out, which loads a classic script.
 *
 * A font or an image is only preloaded: the component's stylesheet or
 * template uses it, the preload has the browser fetch it before that.
 *
 * @internal
 */
final class ResolvedAsset
{
    /**
     * The path the component declared: an AssetMapper logical path, or a URL.
     * The same as $path when the asset map does not know it.
     */
    public readonly string $declaredPath;

    /**
     * @param string                                         $path         the public path, or the URL linked as it is
     * @param Asset::CSS|Asset::JS|Asset::FONT|Asset::IMAGE $type
     * @param array<string, mixed>                           $attributes   the attributes the asset declares
     * @param string|null                                    $declaredPath the path the component declared, when not $path
     */
    public function __construct(
        public readonly string $path,
        public readonly string $type,
        public readonly array $attributes = [],
        ?string $declaredPath = null,
    ) {
        $this->declaredPath = $declaredPath ?? $path;
    }

    public function isStylesheet(): bool
    {
        return Asset::CSS === $this->type;
    }

    public function isScript(): bool
    {
        return Asset::JS === $this->type;
    }

    /**
     * Whether the asset is only preloaded (a font, an image).
     */
    public function isPreload(): bool
    {
        return AssetType::isPreloadOnly($this->type);
    }

    /**
     * Whether the asset is a script loaded as an ES module.
     */
    public function isModule(): bool
    {
        if (!$this->isScript()) {
            return false;
        }

        $type = $this->tagAttributes(null)['type'];

        return \is_string($type) && 'module' === strtolower(trim($type));
    }

    /**
     * The attributes of the tag, after `rel`/`href` or `src`: the bundle's
     * defaults first, then those of the asset, which override any of them.
     * A preload carries the same attributes in the `Link` header.
     *
     * A stylesheet link gets the nonce and `data-turbo-track="dynamic"`.
     * Turbo Drive adds the stylesheets of the next page to <head> and, on
     * navigation, removes those marked `dynamic` that the next page does not
     * have, so the CSS of a component stays on the pages that render it, as it
     * does without Turbo. Without the mark every stylesheet a visit ever loaded
     * stays — a component stylesheet with a global rule would go on applying.
     * Turbo leaves scripts in place whatever their mark, so they get none.
     *
     * A script tag gets `type="module"` and the nonce. `'type' => false`
     * therefore renders a classic script, and `'nonce' => false` a tag without
     * the nonce.
     *
     * A preload link gets `as` — the destination, which the type of the asset
     * names — and the MIME type its extension tells, so that a browser skips
     * a format it does not support. A font also gets `crossorigin`: fonts are
     * always fetched in CORS mode, and a preload fetched in another mode is not
     * reused, so the font would be downloaded twice — same-origin fonts
     * included. A preload needs no nonce: `font-src`/`img-src` govern it.
     *
     * @return array<string, mixed>
     */
    public function tagAttributes(?string $nonce): array
    {
        $defaults = match (true) {
            $this->isStylesheet() => ['nonce' => $nonce, 'data-turbo-track' => 'dynamic'],
            $this->isScript() => ['type' => 'module', 'nonce' => $nonce],
            default => ['as' => $this->type, 'type' => AssetType::mimeType($this->path), 'crossorigin' => Asset::FONT === $this->type ? true : null],
        };

        return array_merge($defaults, $this->attributes);
    }
}
