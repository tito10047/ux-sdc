<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

?>
import { Controller } from '@hotwired/stimulus';

/*
 * The controller of <?= $component_name ?>, attached to it by
 * {{ stimulus_controller(controller) }} in <?= $component_name ?>.html.twig.
 *
 * Its name keeps the capitals of the file path. Targets, actions, classes and
 * values work, but Stimulus does not call fooValueChanged() callbacks for such a
 * name: read this.fooValue where it is needed instead.
 */

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    connect() {
        // Called each time the element of the component is added to the page.
    }
}
