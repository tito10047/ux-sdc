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

use Tito10047\UX\Sdc\Service\AssetType;

/**
 * A component asset as the response links to it: its public path, whether it
 * is a stylesheet or a script, and the attributes its tag gets.
 *
 * Scripts are ES modules (`type="module"`) unless the asset says otherwise:
 * AssetMapper serves JavaScript as modules, and a file with an `import` statement
 * is a syntax error when loaded as a classic script. An asset that sets the
 * `type` attribute itself keeps it, and `'type' => false` leaves the attribute
 * out, which loads a classic script.
 *
 * @internal
 */
final class ResolvedAsset
{
    /**
     * @param string               $path       the public path, or the URL linked as it is
     * @param AssetType::CSS|AssetType::JS $type
     * @param array<string, mixed> $attributes the attributes the asset declares
     */
    public function __construct(
        public readonly string $path,
        public readonly string $type,
        public readonly array $attributes = [],
    ) {
    }

    public function isStylesheet(): bool
    {
        return AssetType::CSS === $this->type;
    }

    /**
     * Whether the asset is a script loaded as an ES module.
     */
    public function isModule(): bool
    {
        if ($this->isStylesheet()) {
            return false;
        }

        $type = $this->tagAttributes(null)['type'];

        return \is_string($type) && 'module' === strtolower(trim($type));
    }

    /**
     * The attributes of the tag, after `rel`/`href` or `src`: the bundle's
     * defaults first, then those of the asset, which override any of them.
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
     * @return array<string, mixed>
     */
    public function tagAttributes(?string $nonce): array
    {
        $defaults = $this->isStylesheet()
            ? ['nonce' => $nonce, 'data-turbo-track' => 'dynamic']
            : ['type' => 'module', 'nonce' => $nonce];

        return array_merge($defaults, $this->attributes);
    }
}
