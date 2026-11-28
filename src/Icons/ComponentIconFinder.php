<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Icons;

use Symfony\Component\Finder\Finder;

/**
 * Lists the `sdc:…` names of every SVG stored in a component directory.
 *
 * On ux-icons 3 it is tagged "ux_icons.finder", so `ux:icons:warm-cache` and
 * the icon cache warmer fill the cache with component icons too. On ux-icons 2
 * nothing calls it, and a component icon is cached on its first rendering.
 */
final class ComponentIconFinder
{
    public function __construct(
        private readonly string $componentsDir,
        private readonly string $subdir = '',
    ) {
    }

    /**
     * @return iterable<string>
     */
    public function icons(): iterable
    {
        $root = realpath($this->componentsDir);
        if (false === $root) {
            return;
        }

        $subdir = trim($this->subdir, '/');

        foreach ((new Finder())->files()->in($root)->name('*.svg') as $file) {
            $directory = $file->getPath();
            if ('' !== $subdir) {
                if (!str_ends_with($directory, DIRECTORY_SEPARATOR . $subdir)) {
                    continue;
                }
                $directory = substr($directory, 0, -\strlen(DIRECTORY_SEPARATOR . $subdir));
            }

            if (null !== $name = ComponentIconName::forDirectory($root, $directory, $file->getBasename('.svg'))) {
                yield $name;
            }
        }
    }
}
