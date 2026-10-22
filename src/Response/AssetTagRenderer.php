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

/**
 * Renders the HTML tag that loads an asset: `<link rel="stylesheet">` for a
 * stylesheet, `<script src>` for a script, `<link rel="preload">` for a font
 * or an image.
 *
 * @internal
 */
final class AssetTagRenderer
{
    /**
     * @param string|null $nonce the CSP nonce of the kind of tag, if any
     */
    public function render(ResolvedAsset $asset, ?string $nonce): string
    {
        $path = htmlspecialchars($asset->path, ENT_QUOTES);
        $attributes = $this->renderAttributes($asset->tagAttributes($nonce));

        return match (true) {
            $asset->isStylesheet() => sprintf('<link rel="stylesheet" href="%s"%s>', $path, $attributes),
            $asset->isScript() => sprintf('<script src="%s"%s></script>', $path, $attributes),
            default => sprintf('<link rel="preload" href="%s"%s>', $path, $attributes),
        };
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
}
