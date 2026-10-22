<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DefaultController extends AbstractController
{
    #[Route('/')]
    public function index(): Response
    {
        $response = $this->render('home.html.twig');
        // Tells the end-to-end test which environment the server really runs in.
        $response->headers->set('X-E2E-Environment', (string) $this->getParameter('kernel.environment'));

        return $response;
    }
}
