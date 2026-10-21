<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Service;

/**
 * The type of a component asset, the one rule the render listeners register
 * assets by and `debug:sdc` reports them by.
 *
 * #[Asset] may leave the type out, in which case the path tells: a `.css`
 * file is a stylesheet, anything else a script. Of the types an asset may
 * declare, only `css` and `js` are loaded.
 *
 * @internal
 */
final class AssetType
{
    public const CSS = 'css';
    public const JS = 'js';

    /**
     * The type an asset is registered with: the one it declares, or the one
     * its path tells when it declares none.
     */
    public static function resolve(string $declared, string $path): string
    {
        return $declared ?: (str_ends_with($path, '.css') ? self::CSS : self::JS);
    }

    /**
     * Whether an asset of the type gets a tag (or a header entry); any other
     * type is left out of the response.
     */
    public static function isLoaded(string $type): bool
    {
        return self::CSS === $type || self::JS === $type;
    }
}
