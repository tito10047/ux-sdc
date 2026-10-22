<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\Esm;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Tito10047\UX\Sdc\Attribute\Asset;

/**
 * Widget.css and Widget.js are discovered; Widget.js is a module. legacy.js
 * opts out of modules and is loaded as a classic script.
 */
#[AsTwigComponent]
#[Asset('Esm/legacy.js', attributes: ['type' => false])]
final class Widget
{
}
