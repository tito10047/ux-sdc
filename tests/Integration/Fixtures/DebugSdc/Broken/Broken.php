<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\DebugSdc\Broken;

use Tito10047\UX\Sdc\Attribute\Asset;
use Tito10047\UX\Sdc\Attribute\AsSdcComponent;

/**
 * No template anywhere, a stylesheet that is not in the asset map, and URLs
 * that are linked as they are — a stylesheet, and a font that is preloaded.
 */
#[AsSdcComponent(css: 'missing/broken.css')]
#[Asset('https://cdn.example.com/broken.css', priority: 5)]
#[Asset('https://cdn.example.com/broken.woff2', type: 'font')]
final class Broken
{
}
