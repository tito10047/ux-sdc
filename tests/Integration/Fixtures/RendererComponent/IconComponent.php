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
 * The component class of a component rendered without a template, as
 * symfony/ux-icons has UXIconComponent: it only declares the props for
 * Twig Components.
 */
final class IconComponent
{
    public string $name;
}
