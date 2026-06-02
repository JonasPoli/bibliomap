<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    public function __construct(
        #[Autowire('%app.google_analytics_id%')] private readonly string $analyticsId,
        #[Autowire('%app.seo.title%')]           private readonly string $seoTitle,
        #[Autowire('%app.seo.description%')]     private readonly string $seoDescription,
        #[Autowire('%app.seo.keywords%')]        private readonly string $seoKeywords,
        #[Autowire('%app.seo.og_image%')]        private readonly string $seoOgImage,
        #[Autowire('%app.base_url%')]            private readonly string $baseUrl,
    ) {}

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // Redirect logged-in users straight to the app
        if ($this->getUser()) {
            return $this->redirectToRoute('app_projects_index');
        }

        return $this->render('home/index.html.twig', [
            'analytics_id'    => $this->analyticsId,
            'seo_title'       => $this->seoTitle,
            'seo_description' => $this->seoDescription,
            'seo_keywords'    => $this->seoKeywords,
            'seo_og_image'    => $this->seoOgImage,
            'base_url'        => $this->baseUrl,
        ]);
    }
}
