<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\Library\Badge;

use Tito10047\UX\Sdc\Attribute\AsSdcComponent;

/**
 * A component outside ux_components_dir, in a directory the application
 * registers as a namespaced Twig path and as a namespaced AssetMapper path.
 */
#[AsSdcComponent('LibraryBadge')]
final class Badge
{
}
