<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\Service\Fixtures\UntypedAsset;

use Tito10047\UX\Sdc\Attribute\Asset;

/**
 * A URL that tells no type, and none declared.
 */
#[Asset('https://cdn.example.com/lib')]
final class UntypedAsset
{
}
