<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\Stimulus;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

if (trait_exists(DefaultActionTrait::class)) {
    /**
     * A LiveComponent with neither the Stimulus trait nor the interface: its
     * re-render request hydrates it instead of mounting it.
     */
    #[AsLiveComponent('stimulus_live_plain', template: 'components/stimulus_controller.html.twig')]
    final class LivePlain
    {
        use DefaultActionTrait;
    }
} else {
    final class LivePlain
    {
    }
}
