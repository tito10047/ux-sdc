<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\Service\Fixtures\Preload;

use Tito10047\UX\Sdc\Attribute\Asset;

/**
 * A font and an image, preloaded; the type of the image is declared, its URL
 * tells none. The bare #[Asset] declares nothing.
 */
#[Asset('fonts/inter.woff2', priority: 10)]
#[Asset('https://cdn.example.com/hero?w=1200', type: Asset::IMAGE, attributes: ['fetchpriority' => 'high'])]
#[Asset]
final class Preload
{
}
