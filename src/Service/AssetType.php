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

use Tito10047\UX\Sdc\Attribute\Asset;

/**
 * The rules about the types of a component asset (the type constants of
 * #[Asset]): which type a path tells, and how a preloaded asset is announced.
 *
 * @internal
 */
final class AssetType
{
    /**
     * Every type an asset may have.
     */
    public const ALL = [Asset::CSS, Asset::JS, Asset::FONT, Asset::IMAGE];

    /**
     * The type each extension tells. Anything else tells none.
     */
    private const TYPES = [
        'css' => Asset::CSS,
        'js' => Asset::JS,
        'mjs' => Asset::JS,
        'woff2' => Asset::FONT,
        'woff' => Asset::FONT,
        'ttf' => Asset::FONT,
        'otf' => Asset::FONT,
        'avif' => Asset::IMAGE,
        'webp' => Asset::IMAGE,
        'png' => Asset::IMAGE,
        'jpg' => Asset::IMAGE,
        'jpeg' => Asset::IMAGE,
        'gif' => Asset::IMAGE,
        'svg' => Asset::IMAGE,
    ];

    /**
     * The MIME type of a preloaded file, by its extension. A browser skips a
     * preload of a type it does not support (an AVIF image, a WOFF2 font)
     * instead of downloading a file it cannot use.
     */
    private const MIME_TYPES = [
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'avif' => 'image/avif',
        'webp' => 'image/webp',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
    ];

    /**
     * The type the extension of a path tells, case-insensitively; the query
     * and fragment of a URL are not part of it.
     *
     * @return Asset::CSS|Asset::JS|Asset::FONT|Asset::IMAGE|null null when the extension tells none
     */
    public static function fromPath(string $path): ?string
    {
        return self::TYPES[self::extension($path)] ?? null;
    }

    /**
     * Whether an asset of the type is only preloaded: its file is used by the
     * component's stylesheet or template, not by a tag of its own.
     */
    public static function isPreloadOnly(string $type): bool
    {
        return Asset::FONT === $type || Asset::IMAGE === $type;
    }

    /**
     * The MIME type of a file to preload, by its extension.
     */
    public static function mimeType(string $path): ?string
    {
        return self::MIME_TYPES[self::extension($path)] ?? null;
    }

    private static function extension(string $path): string
    {
        return strtolower(pathinfo(substr($path, 0, strcspn($path, '?#')), \PATHINFO_EXTENSION));
    }
}
