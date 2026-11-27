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

/**
 * The name a component-local icon goes by inside symfony/ux-icons.
 *
 * In a component's template, `ux_icon('arrow')` is rewritten at compile time
 * to `sdc:<component path>:arrow`, e.g. `sdc:form:input:arrow` for a template
 * in `<ux_components_dir>/Form/Input/`. The name is unique per component, so
 * the icon cache of ux-icons never mixes up two components' `arrow.svg`, and it
 * passes `Icon::isValidName()` (lowercase kebab segments joined by colons).
 */
final class ComponentIconName
{
    public const PREFIX = 'sdc';

    private const SEGMENT = '#^[a-z0-9]+(?:-[a-z0-9]+)*$#';

    /**
     * Kebab-cases one directory name: "FormInput" → "form-input".
     */
    public static function segment(string $directoryName): string
    {
        $kebab = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '-', $directoryName);
        $kebab = strtolower(str_replace(['_', ' ', '.'], '-', (string) $kebab));

        return trim((string) preg_replace('/-+/', '-', $kebab), '-');
    }

    /**
     * The name an icon written as $icon in a template of $directory has, or
     * null when that template is not inside a component directory or its path
     * cannot be expressed as a valid icon name.
     */
    public static function forDirectory(string $componentsRoot, string $directory, string $icon): ?string
    {
        if (!preg_match(self::SEGMENT, $icon)) {
            return null;
        }

        $root = rtrim($componentsRoot, '/\\');
        $directory = rtrim($directory, '/\\');

        if ($directory === $root) {
            return self::PREFIX . ':' . $icon;
        }

        if (!str_starts_with($directory, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $segments = [];
        foreach (preg_split('#[/\\\\]#', substr($directory, \strlen($root) + 1)) as $part) {
            $segment = self::segment($part);
            if (!preg_match(self::SEGMENT, $segment)) {
                return null;
            }
            $segments[] = $segment;
        }

        return self::PREFIX . ':' . implode(':', $segments) . ':' . $icon;
    }

    /**
     * Splits a name made by forDirectory() back into its directory segments and
     * the icon, or returns null for any other name.
     *
     * @return array{0: list<string>, 1: string}|null
     */
    public static function parse(string $name): ?array
    {
        if (!str_starts_with($name, self::PREFIX . ':')) {
            return null;
        }

        $parts = explode(':', substr($name, \strlen(self::PREFIX) + 1));
        $icon = array_pop($parts);

        if (null === $icon || '' === $icon) {
            return null;
        }

        return [$parts, $icon];
    }
}
