<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Form\BibliometricProjectType;
use App\Repository\BibliometricProjectRepository;
use App\Service\SlugService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/projects')]
#[IsGranted('ROLE_USER')]
class ProjectController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BibliometricProjectRepository $projectRepo,
        private readonly SlugService $slugService,
    ) {}

    #[Route('', name: 'app_projects_index', methods: ['GET'])]
    public function index(): Response
    {
        $projects = $this->projectRepo->findByUser($this->getUser()->getId());

        return $this->render('project/index.html.twig', [
            'projects' => $projects,
        ]);
    }

    #[Route('/new', name: 'app_projects_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $project = new BibliometricProject();
        $form = $this->createForm(BibliometricProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $project->setUser($this->getUser());
            $project->setSlug($this->slugService->generate($project->getTitle()));

            $this->em->persist($project);
            $this->em->flush();

            $this->addFlash('success', 'Projeto criado com sucesso!');
            return $this->redirectToRoute('app_projects_show', ['id' => $project->getId()]);
        }

        return $this->render('project/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_projects_show', methods: ['GET'])]
    public function show(
        BibliometricProject $project,
        \App\Service\Analytics\IndicatorService $indicators
    ): Response {
        $this->denyAccessUnlessGranted('view', $project);

        $summary = $indicators->summary($project->getId());

        return $this->render('project/show.html.twig', [
            'project' => $project,
            'summary' => $summary,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_projects_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, BibliometricProject $project): Response
    {
        $this->denyAccessUnlessGranted('edit', $project);

        $form = $this->createForm(BibliometricProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Projeto atualizado com sucesso!');
            return $this->redirectToRoute('app_projects_show', ['id' => $project->getId()]);
        }

        return $this->render('project/edit.html.twig', [
            'project' => $project,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/archive', name: 'app_projects_archive', methods: ['POST'])]
    public function archive(Request $request, BibliometricProject $project): Response
    {
        $this->denyAccessUnlessGranted('edit', $project);

        if ($this->isCsrfTokenValid('archive' . $project->getId(), $request->getPayload()->getString('_token'))) {
            $project->setStatus(BibliometricProject::STATUS_ARCHIVED);
            $this->em->flush();
            $this->addFlash('success', 'Projeto arquivado.');
        }

        return $this->redirectToRoute('app_projects_index');
    }
}
