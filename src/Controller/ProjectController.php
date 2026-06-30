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
            $project->setSlug($this->slugService->generate($project->getTitle(), BibliometricProject::class));

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

    #[Route('/{id}/delete', name: 'app_projects_delete', methods: ['POST'])]
    public function delete(Request $request, BibliometricProject $project): Response
    {
        $this->denyAccessUnlessGranted('delete', $project);

        if ($this->isCsrfTokenValid('delete' . $project->getId(), $request->getPayload()->getString('_token'))) {
            $projectId = $project->getId();
            $projectTitle = $project->getTitle();

            // 1. Gather all files to delete before removing from database
            $projectDir = $this->getParameter('kernel.project_dir');
            $filePaths = [];
            foreach ($project->getDatasets() as $dataset) {
                if ($dataset->getFilePath()) {
                    $filePaths[] = $dataset->getFilePath();
                }
                $logFile = $projectDir . '/var/log/import_' . $dataset->getId() . '.log';
                if (file_exists($logFile)) {
                    $filePaths[] = $logFile;
                }
            }

            // 2. Perform DB deletion in a transaction to be extremely fast and secure
            $this->em->beginTransaction();
            try {
                $conn = $this->em->getConnection();

                // 2.1 Delete dataset skips
                $conn->executeStatement(
                    'DELETE FROM dataset_skip WHERE dataset_id IN (SELECT id FROM dataset WHERE project_id = ?)',
                    [$projectId]
                );

                // 2.2 Delete document authors
                $conn->executeStatement(
                    'DELETE FROM document_author WHERE document_id IN (SELECT id FROM document WHERE project_id = ?)',
                    [$projectId]
                );

                // 2.3 Delete document keywords
                $conn->executeStatement(
                    'DELETE FROM document_keyword WHERE document_id IN (SELECT id FROM document WHERE project_id = ?)',
                    [$projectId]
                );

                // 2.4 Delete documents
                $conn->executeStatement(
                    'DELETE FROM document WHERE project_id = ?',
                    [$projectId]
                );

                // 2.5 Delete datasets
                $conn->executeStatement(
                    'DELETE FROM dataset WHERE project_id = ?',
                    [$projectId]
                );

                // 2.6 Let Doctrine remove the project entity so the Unit of Work is updated correctly
                $this->em->remove($project);
                $this->em->flush();

                $this->em->commit();
            } catch (\Throwable $e) {
                $this->em->rollback();
                $this->addFlash('danger', 'Erro ao excluir o projeto do banco de dados: ' . $e->getMessage());
                return $this->redirectToRoute('app_projects_edit', ['id' => $projectId]);
            }

            // 3. Delete physical files on disk
            foreach ($filePaths as $path) {
                if (file_exists($path)) {
                    @unlink($path);
                }
            }

            // 4. Delete upload directory for this project
            $uploadDir = $projectDir . '/var/uploads/' . $projectId;
            if (is_dir($uploadDir)) {
                $files = glob($uploadDir . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
                @rmdir($uploadDir);
            }

            $this->addFlash('success', sprintf('Projeto "%s" e todos os seus dados foram excluídos com sucesso.', $projectTitle));
        } else {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_projects_edit', ['id' => $project->getId()]);
        }

        return $this->redirectToRoute('app_projects_index');
    }
}
