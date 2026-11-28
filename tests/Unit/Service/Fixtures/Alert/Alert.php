<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\Service\Fixtures\Alert;

use Tito10047\UX\Sdc\Attribute\Asset;

/**
 * A component with a stylesheet, a script and a template next to it, and one
 * explicit asset.
 */
#[Asset(path: 'vendor/explicit.css', priority: 3)]
final class Alert
{
}
