<?= "<?php\n" ?>

namespace <?= $namespace ?>;

use Tito10047\UX\Sdc\Attribute\AsSdcComponent;
<?php if ($with_stimulus): ?>
use Tito10047\UX\Sdc\Twig\ComponentNamespaceInterface;
use Tito10047\UX\Sdc\Twig\Stimulus;
<?php endif; ?>

#[AsSdcComponent]
class <?= $class_name ?><?php if ($with_stimulus): ?> implements ComponentNamespaceInterface<?php endif; ?>

{
<?php if ($with_stimulus): ?>
    use Stimulus;
<?php endif; ?>
}
