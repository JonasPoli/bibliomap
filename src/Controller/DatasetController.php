<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Entity\Dataset;
use App\Repository\DatasetRepository;
use App\Repository\DocumentRepository;
use App\Service\Import\ImporterResolver;
use App\Service\Import\ImportQueueInspector;
use Psr\Log\LoggerInterface;
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
        private readonly ImporterResolver $resolver,
        private readonly ImportQueueInspector $queueInspector,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Lista todos os datasets do projeto
     */
    #[Route('', name: 'app_datasets_index', methods: ['GET'])]
    public function index(int $projectId): Response
    {
        $project = $this->getProject($projectId, 'view');
        $datasets = $this->datasetRepo->findBy(
            ['project' => $project],
            ['createdAt' => 'DESC']
        );

        return $this->render('dataset/index.html.twig', [
            'project' => $project,
            'datasets' => $datasets,
            // Só o dono pode retomar; só consulta as travas quando há dataset parado em pendente/importando
            'interrupted' => $this->isGranted('edit', $project) && array_filter($datasets, fn (Dataset $d) => in_array($d->getStatus(), [Dataset::STATUS_PENDING, Dataset::STATUS_IMPORTING], true))
                ? $this->queueInspector->interruptedDatasetIds($project->getId())
                : [],
        ]);
    }

    /**
     * Detalhe de um dataset — documentos + stats
     */
    #[Route('/{id}', name: 'app_datasets_show', methods: ['GET'])]
    public function show(int $projectId, Dataset $dataset, Request $request): Response
    {
        $project = $this->getProject($projectId, 'view');
        $this->assertDatasetBelongs($dataset, $project);

        $page = max(1, (int) $request->query->get('page', 1));
        $documents = $this->documentRepo->findByDataset($dataset->getId(), $page, 50);
        $totalDocs = $this->documentRepo->countByDataset($dataset->getId());

        $pageSkip = max(1, (int) $request->query->get('page_skip', 1));
        $limitSkip = 50;

        $skipRepo = $this->em->getRepository(\App\Entity\DatasetSkip::class);
        $skips = $skipRepo->findBy(
            ['dataset' => $dataset],
            ['id' => 'ASC'],
            $limitSkip,
            ($pageSkip - 1) * $limitSkip
        );
        $totalSkips = $skipRepo->count(['dataset' => $dataset]);

        // Auto-determine active tab based on query param
        $activeTab = $request->query->has('page_skip') || $request->query->get('tab') === 'duplicates' ? 'duplicates' : 'imported';

        return $this->render('dataset/show.html.twig', [
            'project' => $project,
            'dataset' => $dataset,
            'documents' => $documents,
            'totalDocs' => $totalDocs,
            'currentPage' => $page,
            'totalPages' => (int) ceil($totalDocs / 50),
            'skips' => $skips,
            'totalSkips' => $totalSkips,
            'currentPageSkip' => $pageSkip,
            'totalPagesSkip' => (int) ceil($totalSkips / $limitSkip),
            'activeTab' => $activeTab,
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
            if (!$this->isCsrfTokenValid('dataset_edit_' . $dataset->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token CSRF inválido. Recarregue a página e tente de novo.');
                return $this->redirectToRoute('app_datasets_edit', ['projectId' => $projectId, 'id' => $dataset->getId()]);
            }

            $dataset->setName(trim($request->request->get('name', $dataset->getName())));
            $dataset->setDescription(trim($request->request->get('description', '')) ?: null);

            // Só valores da lista de bases (os cartões da tela)
            $source = (string) $request->request->get('source');
            if ($source !== '' && in_array($source, $this->resolver->allowedSources(), true)) {
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
        $conn = $this->em->getConnection();

        // Importação em andamento (trava do dataset ocupada por outro processo): apagar agora
        // deixaria o processo gravando documentos sem dataset.
        $lockName = 'bibliomap_import_dataset_' . $dataset->getId();
        if ((int) $conn->fetchOne('SELECT GET_LOCK(?, 0)', [$lockName]) !== 1) {
            $this->addFlash('warning', 'Este dataset está sendo importado agora. Espere terminar para excluí-lo.');
            return $this->redirectToRoute('app_datasets_index', ['projectId' => $projectId]);
        }

        $filePath = $dataset->getFilePath();
        try {
            // Em faixas: um DELETE único de dezenas de milhares de documentos (com os vínculos em
            // CASCADE) segura bloqueios e o log do InnoDB por minutos.
            do {
                $deleted = (int) $conn->executeStatement(
                    'DELETE FROM document WHERE dataset_id = ? ORDER BY id LIMIT 2000',
                    [$dataset->getId()]
                );
            } while ($deleted > 0);

            $this->em->remove($dataset);
            $this->em->flush();
        } finally {
            try {
                $conn->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName]);
            } catch (\Throwable) {}
        }

        $this->removeUploadedFile($filePath, $project);

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

    /** Apaga o arquivo enviado, se está na pasta de uploads deste projeto e nenhum outro dataset o usa. */
    private function removeUploadedFile(?string $path, BibliometricProject $project): void
    {
        if (!$path) {
            return;
        }
        try {
            $root = realpath($this->getParameter('kernel.project_dir') . '/var/uploads/' . $project->getId());
            $real = realpath($path);
            if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                return;
            }
            $others = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM dataset WHERE file_path = ?', [$path]);
            if ($others === 0) {
                @unlink($real);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Não foi possível apagar o arquivo do dataset: ' . $e->getMessage());
        }
    }

    /** Listar/ver vale para projeto público ('view'); editar/excluir só o dono ('edit'). */
    private function getProject(int $projectId, string $attribute = 'edit'): BibliometricProject
    {
        $project = $this->em->getRepository(BibliometricProject::class)->find($projectId);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted($attribute, $project);
        return $project;
    }

    private function assertDatasetBelongs(Dataset $dataset, BibliometricProject $project): void
    {
        if ($dataset->getProject() !== $project) {
            throw $this->createAccessDeniedException();
        }
    }
}
