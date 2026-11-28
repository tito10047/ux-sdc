<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\ExplicitTemplate;

use Tito10047\UX\Sdc\Attribute\AsSdcComponent;

/**
 * Names its template explicitly and has another one next to its class: the
 * explicit one is rendered, the stylesheet next to the class is still loaded.
 */
#[AsSdcComponent(template: 'components/explicit_template.html.twig')]
final class ExplicitSdcComponent
{
}
