<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\InvalidAsset\Untyped;

use Tito10047\UX\Sdc\Attribute\Asset;
use Tito10047\UX\Sdc\Attribute\AsSdcComponent;

/**
 * A URL whose type its path does not tell, and no type declared. Before 1.0
 * it was loaded as a script.
 */
#[AsSdcComponent]
#[Asset('https://cdn.example.com/lib')]
final class Untyped
{
}
