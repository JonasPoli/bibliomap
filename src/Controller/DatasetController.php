<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Entity\Dataset;
use App\Repository\DatasetRepository;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/projects/{projectId}/datasets')]
#[IsGranted('ROLE_USER')]
class DatasetController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DatasetRepository $datasetRepo,
        private readonly DocumentRepository $documentRepo,
    ) {}

    /**
     * Lista todos os datasets do projeto
     */
    #[Route('', name: 'app_datasets_index', methods: ['GET'])]
    public function index(int $projectId): Response
    {
        $project = $this->getProject($projectId);
        $datasets = $this->datasetRepo->findBy(
            ['project' => $project],
            ['createdAt' => 'DESC']
        );

        return $this->render('dataset/index.html.twig', [
            'project' => $project,
            'datasets' => $datasets,
        ]);
    }

    /**
     * Detalhe de um dataset — documentos + stats
     */
    #[Route('/{id}', name: 'app_datasets_show', methods: ['GET'])]
    public function show(int $projectId, Dataset $dataset, Request $request): Response
    {
        $project = $this->getProject($projectId);
        $this->assertDatasetBelongs($dataset, $project);

        $page = max(1, (int) $request->query->get('page', 1));
        $documents = $this->documentRepo->findByDataset($dataset->getId(), $page, 50);
        $totalDocs = $this->documentRepo->countByDataset($dataset->getId());

        return $this->render('dataset/show.html.twig', [
            'project' => $project,
            'dataset' => $dataset,
            'documents' => $documents,
            'totalDocs' => $totalDocs,
            'currentPage' => $page,
            'totalPages' => (int) ceil($totalDocs / 50),
        ]);
    }

    /**
     * Editar nome, descrição e metadados do dataset
     */
    #[Route('/{id}/edit', name: 'app_datasets_edit', methods: ['GET', 'POST'])]
    public function edit(int $projectId, Dataset $dataset, Request $request): Response
    {
        $project = $this->getProject($projectId);
        $this->assertDatasetBelongs($dataset, $project);

        if ($request->isMethod('POST')) {
            $dataset->setName(trim($request->request->get('name', $dataset->getName())));
            $dataset->setDescription(trim($request->request->get('description', '')) ?: null);

            $source = $request->request->get('source');
            if ($source) {
                $dataset->setSource($source);
            }

            $startRaw = $request->request->get('search_period_start');
            $endRaw = $request->request->get('search_period_end');

            try {
                $dataset->setSearchPeriodStart($startRaw ? new \DateTimeImmutable($startRaw) : null);
                $dataset->setSearchPeriodEnd($endRaw ? new \DateTimeImmutable($endRaw) : null);
            } catch (\Throwable) {}

            $this->em->flush();
            $this->addFlash('success', 'Dataset atualizado com sucesso!');
            return $this->redirectToRoute('app_datasets_show', [
                'projectId' => $projectId,
                'id' => $dataset->getId(),
            ]);
        }

        return $this->render('dataset/edit.html.twig', [
            'project' => $project,
            'dataset' => $dataset,
        ]);
    }

    /**
     * Excluir dataset e todos os seus documentos
     */
    #[Route('/{id}/delete', name: 'app_datasets_delete', methods: ['POST'])]
    public function delete(int $projectId, Dataset $dataset, Request $request): Response
    {
        $project = $this->getProject($projectId);
        $this->assertDatasetBelongs($dataset, $project);

        if (!$this->isCsrfTokenValid('delete_dataset_' . $dataset->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token inválido.');
            return $this->redirectToRoute('app_datasets_index', ['projectId' => $projectId]);
        }

        $name = $dataset->getName();

        // Cascade delete documents via raw query (faster than Doctrine for large sets)
        $this->em->getConnection()->executeStatement(
            'DELETE FROM document WHERE dataset_id = ?',
            [$dataset->getId()]
        );

        $this->em->remove($dataset);
        $this->em->flush();

        // Recalculate project status
        $totalDocs = $this->documentRepo->countByProject($project->getId());
        $project->setStatus($totalDocs > 0
            ? \App\Entity\BibliometricProject::STATUS_READY
            : \App\Entity\BibliometricProject::STATUS_DRAFT
        );
        $this->em->flush();

        $this->addFlash('success', sprintf('Dataset "%s" e seus documentos foram removidos.', $name));
        return $this->redirectToRoute('app_datasets_index', ['projectId' => $projectId]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function getProject(int $projectId): BibliometricProject
    {
        $project = $this->em->getRepository(BibliometricProject::class)->find($projectId);
        if (!$project || $project->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        return $project;
    }

    private function assertDatasetBelongs(Dataset $dataset, BibliometricProject $project): void
    {
        if ($dataset->getProject() !== $project) {
            throw $this->createAccessDeniedException();
        }
    }
}
