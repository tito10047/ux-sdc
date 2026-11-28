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
<?= "<?php\n" ?>

namespace <?= $namespace ?>;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class <?= $class_name ?> extends AbstractController
{
    #[Route(<?= var_export($route_path, true) ?>, name: <?= var_export($route_name, true) ?>)]
    public function __invoke(): Response
    {
        return $this->render(<?= var_export($template_name, true) ?>);
    }
}
