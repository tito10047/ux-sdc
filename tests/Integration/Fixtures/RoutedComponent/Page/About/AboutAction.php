<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\RoutedComponent\Page\About;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A route without a name: the attribute loader derives one from the class and
 * method, the same for every import of the class.
 */
final class AboutAction extends AbstractController
{
    #[Route('/about')]
    public function __invoke(): Response
    {
        return new Response('About');
    }
}
