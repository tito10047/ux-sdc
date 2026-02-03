<div {{ attributes.defaults({class:"<?= \Symfony\Bundle\MakerBundle\Str::asSnakeCase($component_name) ?>"})<?php if ($with_stimulus): ?>.defaults(stimulus_controller(controller))<?php endif; ?> }}>
    <!-- Component: <?= $component_name ?> -->
</div>
