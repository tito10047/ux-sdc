<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\Preload;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Tito10047\UX\Sdc\Attribute\Asset;

/**
 * Hero.css is discovered and uses inter.woff2; the font and the image the
 * template shows are only preloaded. Neither declares its type: the extension
 * tells it (before 1.0, both were loaded as scripts).
 */
#[AsTwigComponent]
#[Asset('Preload/inter.woff2')]
#[Asset('Preload/hero.avif', priority: 5, attributes: ['fetchpriority' => 'high'])]
final class Hero
{
}
