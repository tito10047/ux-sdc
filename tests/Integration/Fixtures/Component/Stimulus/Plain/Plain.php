<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\Stimulus\Plain;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Neither the Stimulus trait nor ComponentNamespaceInterface.
 */
#[AsTwigComponent('stimulus_plain', template: 'components/stimulus_controller.html.twig')]
final class Plain
{
}
