<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LegalController extends AbstractController
{
    /** Public on purpose (no IsGranted): it has to be readable before signing up. */
    #[Route('/legal', name: 'app_legal')]
    public function index(): Response
    {
        return $this->render('legal/index.html.twig');
    }
}
