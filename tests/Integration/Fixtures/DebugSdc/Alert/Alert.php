<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\DebugSdc\Alert;

use Tito10047\UX\Sdc\Attribute\Asset;
use Tito10047\UX\Sdc\Attribute\AsSdcComponent;

/**
 * A component debug:sdc has nothing to complain about: template, CSS, JS and
 * Stimulus controller next to the class, a font it preloads, and an action
 * with a route.
 */
#[AsSdcComponent]
#[Asset('Alert/Alert.woff2')]
final class Alert
{
}
