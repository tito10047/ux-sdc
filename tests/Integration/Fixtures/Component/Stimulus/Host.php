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

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Embeds stimulus_plain, which receives the context of this template.
 */
#[AsTwigComponent('stimulus_host', template: 'components/stimulus_host.html.twig')]
final class Host
{
}
