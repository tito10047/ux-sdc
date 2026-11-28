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

use Symfony\UX\Icons\Exception\IconNotFoundException;
use Symfony\UX\Icons\Icon;
use Symfony\UX\Icons\IconFactory;
use Symfony\UX\Icons\IconRegistryInterface;

/**
 * Serves `sdc:…` icons from the component's own directory.
 *
 * It sits in the ux-icons registry chain (tag "ux_icons.registry") in front of
 * the local and the Iconify registries, so everything ux-icons does around a
 * registry still applies: the icon cache, `ignore_not_found`, the default
 * attributes and `icon_sets.sdc.icon_attributes`.
 *
 * Any other name is refused straight away, so it costs the other icons nothing.
 */
final class ComponentIconRegistry implements IconRegistryInterface
{
    /** @var array<string, string|null> directory segments → real directory */
    private array $directories = [];

    public function __construct(
        private readonly IconFactory $iconFactory,
        private readonly string $componentsDir,
        private readonly string $subdir = '',
        private readonly ?IconRegistryInterface $fallback = null,
    ) {
    }

    public function get(string $name): Icon
    {
        if (null === $parsed = ComponentIconName::parse($name)) {
            throw new IconNotFoundException(\sprintf('The icon "%s" is not a component icon.', $name));
        }

        [$segments, $icon] = $parsed;

        if (null !== $file = $this->file($segments, $icon)) {
            return $this->iconFactory->fromFile($file);
        }

        // The template asked for a bare name the component does not have:
        // it is the global icon of that name, as it would be anywhere else.
        if (null !== $this->fallback) {
            return $this->fallback->get($icon);
        }

        throw new IconNotFoundException(\sprintf('The component icon "%s" does not exist.', $name));
    }

    /**
     * @param list<string> $segments
     */
    private function file(array $segments, string $icon): ?string
    {
        if (null === $directory = $this->directory($segments)) {
            return null;
        }

        $file = $directory . ('' !== $this->subdir ? '/' . trim($this->subdir, '/') : '') . '/' . $icon . '.svg';

        return is_file($file) ? $file : null;
    }

    /**
     * Finds the directory whose kebab-cased path is $segments. It is only
     * reached when the icon cache misses, and the answer is kept.
     *
     * @param list<string> $segments
     */
    private function directory(array $segments): ?string
    {
        $key = implode(':', $segments);
        if (\array_key_exists($key, $this->directories)) {
            return $this->directories[$key];
        }

        $directory = realpath($this->componentsDir) ?: null;
        foreach ($segments as $segment) {
            if (null === $directory) {
                break;
            }

            $match = null;
            foreach (scandir($directory) ?: [] as $entry) {
                if ('.' !== $entry[0] && ComponentIconName::segment($entry) === $segment && is_dir($directory . '/' . $entry)) {
                    $match = $directory . '/' . $entry;
                    break;
                }
            }
            $directory = $match;
        }

        return $this->directories[$key] = $directory;
    }
}
