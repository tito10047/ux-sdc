<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\DebugSdc\Quiet;

use Tito10047\UX\Sdc\Attribute\AsSdcComponent;

/**
 * Its template mentions "controller" only as a key and an attribute, never as
 * the variable: no Stimulus controller is expected.
 */
#[AsSdcComponent]
final class Quiet
{
}
