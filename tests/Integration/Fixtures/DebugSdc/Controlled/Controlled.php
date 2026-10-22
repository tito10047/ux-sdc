<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\DebugSdc\Controlled;

use Tito10047\UX\Sdc\Attribute\AsSdcComponent;

/**
 * Its template uses {{ controller }}, but there is no Controlled_controller.js.
 */
#[AsSdcComponent]
final class Controlled
{
}
