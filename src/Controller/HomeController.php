<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // Redirect logged-in active users straight to the app
        if ($this->getUser()) {
            return $this->redirectToRoute('app_projects_index');
        }

        // site_settings is injected globally via SiteSettingsExtension Twig global
        return $this->render('home/index.html.twig');
    }
}
