<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\Stimulus\Page;

use Symfony\Component\HttpFoundation\Response;
use Tito10047\UX\Sdc\Twig\ComponentNamespaceInterface;
use Tito10047\UX\Sdc\Twig\Stimulus;

/**
 * A page controller, the kind make:sdc-component --action puts next to its
 * component, that needs the controller name in PHP.
 */
final class PageAction implements ComponentNamespaceInterface
{
    use Stimulus;

    public function __invoke(): Response
    {
        return new Response($this->getController());
    }
}
