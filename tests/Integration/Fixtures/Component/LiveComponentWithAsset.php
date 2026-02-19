<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Tito10047\UX\Sdc\Attribute\Asset;
use Tito10047\UX\Sdc\Twig\ComponentNamespaceInterface;
use Tito10047\UX\Sdc\Twig\Stimulus;

if (trait_exists(\Symfony\UX\LiveComponent\DefaultActionTrait::class)) {
    #[AsLiveComponent('LiveComponentWithAsset')]
    #[Asset]
    class LiveComponentWithAsset implements ComponentNamespaceInterface
    {
        use \Symfony\UX\LiveComponent\DefaultActionTrait;
        use Stimulus;
    }
} else {
    class LiveComponentWithAsset implements ComponentNamespaceInterface
    {
        use Stimulus;
    }
}
