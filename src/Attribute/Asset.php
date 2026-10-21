<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Attribute;

use Attribute;
use InvalidArgumentException;
use Tito10047\UX\Sdc\Service\AssetType;

/**
 * An asset loaded with the component, other than the files discovered next to
 * its class.
 *
 * The type says how the page loads it:
 *
 *  - `css` (self::CSS) — a stylesheet, `<link rel="stylesheet">`;
 *  - `js` (self::JS) — a script, `<script type="module">`;
 *  - `font` (self::FONT) and `image` (self::IMAGE) — only preloaded,
 *    `<link rel="preload" as="font|image">` and the same `Link` header: the
 *    component's stylesheet or template is what uses the file, the preload
 *    makes the browser fetch it before it finds that out.
 *
 * Left out, the type is the one the extension of the path tells (`.css`;
 * `.js`, `.mjs`; `.woff2`, `.woff`, `.ttf`, `.otf`; `.avif`, `.webp`, `.png`,
 * `.jpg`, `.jpeg`, `.gif`, `.svg`). A path that tells none — a URL without an
 * extension, any other file — has to declare it: nothing is loaded as a type
 * guessed wrong.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Asset
{
    public const CSS = 'css';
    public const JS = 'js';
    public const FONT = 'font';
    public const IMAGE = 'image';

    /**
     * One of the type constants; null only without a path.
     */
    public readonly ?string $type;

    /**
     * @param string|null          $path       an AssetMapper logical path or a URL; an attribute without
     *                                         one is ignored
     * @param string|null          $type       one of the type constants; null to take the one the extension
     *                                         of the path tells
     * @param array<string, mixed> $attributes attributes of the tag, overriding those the bundle sets
     *
     * @throws InvalidArgumentException when the path is empty, the type is none of the type
     *                                  constants, or no type is given and the path tells none
     */
    public function __construct(
        public readonly ?string $path = null,
        ?string $type = null,
        public readonly int $priority = 0,
        public readonly array $attributes = [],
    ) {
        if (null !== $path && '' === trim($path)) {
            throw new InvalidArgumentException('The path of an #[Asset] is empty.');
        }

        if (null !== $type && !\in_array($type, AssetType::ALL, true)) {
            throw new InvalidArgumentException(\sprintf('The type "%s" of the asset "%s" is not one of "%s".', $type, $path, implode('", "', AssetType::ALL)));
        }

        if (null === $type && null !== $path) {
            $type = AssetType::fromPath($path)
                ?? throw new InvalidArgumentException(\sprintf('The type of the asset "%s" cannot be told from its path. Declare it: #[Asset(type: …)] with one of "%s".', $path, implode('", "', AssetType::ALL)));
        }

        $this->type = $type;
    }
}
