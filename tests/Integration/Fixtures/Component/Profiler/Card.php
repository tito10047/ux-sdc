<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\Profiler;

use Tito10047\UX\Sdc\Attribute\AsSdcComponent;
use Tito10047\UX\Sdc\Attribute\Asset;

/**
 * An asset from each source: Card.css is discovered, card-behaviour.js comes
 * from #[AsSdcComponent], and Profiler/missing.css from #[Asset] is not in
 * the asset map.
 */
#[AsSdcComponent(js: 'Profiler/card-behaviour.js')]
#[Asset('Profiler/missing.css', priority: 10)]
final class Card
{
}
