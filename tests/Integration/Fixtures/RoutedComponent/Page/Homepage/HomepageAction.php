<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\RoutedComponent\Page\Homepage;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Shaped like the controller make:sdc-component --action generates.
 */
final class HomepageAction extends AbstractController
{
    #[Route('/homepage', name: 'app_homepage')]
    public function __invoke(): Response
    {
        return new Response('Homepage');
    }
}
