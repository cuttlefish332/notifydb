<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DocsController extends AbstractController
{
    #[Route('/docs', name: 'docs_index', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('docs/index.html.twig');
    }
}
