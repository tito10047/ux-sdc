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
<div {{ attributes.defaults({class: '<?= $css_class ?>'})<?php if ($with_stimulus): ?>.defaults(stimulus_controller(controller))<?php endif; ?> }}>
    {# The markup of <?= $component_name ?> #}
</div>
