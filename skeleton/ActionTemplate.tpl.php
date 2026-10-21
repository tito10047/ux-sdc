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
{% extends <?= var_export($layout, true) ?> %}

{% block <?= $block ?> %}
    <twig:<?= $full_twig_component_name ?> />
{% endblock %}
