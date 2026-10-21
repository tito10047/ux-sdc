<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\Stimulus\Date_Picker;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Underscores in the path: StimulusBundle turns them into dashes.
 */
#[AsTwigComponent('stimulus_date_picker', template: 'components/stimulus_controller.html.twig')]
final class Date_Picker
{
}
