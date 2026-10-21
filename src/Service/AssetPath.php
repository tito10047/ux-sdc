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
 * Tells an asset path the page links to as it is from one meant for the asset map.
 *
 * #[Asset] and #[AsSdcComponent] take either an AssetMapper logical path
 * (`Alert/Alert.css`, `lib/ui/Badge/Badge.js`) or a URL the application
 * serves some other way: absolute (`https://cdn.example.com/x.css`, `data:…`),
 * protocol-relative (`//cdn.example.com/x.css`) or root-relative
 * (`/build/app.css`). A logical path never starts with a scheme or a slash.
 *
 * @internal
 */
final class AssetPath
{
    /**
     * Whether the path is a URL, linked as it is, rather than a logical path
     * the asset map is expected to know.
     */
    public static function isUrl(string $path): bool
    {
        return str_starts_with($path, '/') || 1 === preg_match('~^[a-z][a-z0-9+.-]*:~i', $path);
    }
}
