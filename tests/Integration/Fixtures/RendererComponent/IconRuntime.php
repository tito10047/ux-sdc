<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\RendererComponent;

/**
 * Renders the component in place of a template, as UXIconRuntime of
 * symfony/ux-icons does: Twig Components hands it the props.
 */
final class IconRuntime
{
    /**
     * @param array<string, mixed> $args
     */
    public function render(array $args = []): string
    {
        $name = $args['name'] ?? '';

        return \sprintf('<svg data-icon="%s"></svg>', htmlspecialchars(\is_string($name) ? $name : '', \ENT_QUOTES));
    }
}
