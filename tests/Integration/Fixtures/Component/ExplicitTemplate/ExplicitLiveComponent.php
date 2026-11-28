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

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;

if (trait_exists(\Symfony\UX\LiveComponent\DefaultActionTrait::class)) {
    /**
     * Names its template explicitly and has another one next to its class:
     * the explicit one is rendered, on the page and on a re-render alike.
     */
    #[AsLiveComponent('ExplicitLiveComponent', template: 'components/explicit_live_template.html.twig')]
    final class ExplicitLiveComponent
    {
        use \Symfony\UX\LiveComponent\DefaultActionTrait;
    }
} else {
    final class ExplicitLiveComponent
    {
    }
}
