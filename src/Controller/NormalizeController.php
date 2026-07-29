<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Service\Normalize\NormalizationService;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use League\Csv\Writer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/projects/{id}/normalize')]
#[IsGranted('ROLE_USER')]
class NormalizeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NormalizationService $normalizationService,
    ) {}

    #[Route('', name: 'app_normalize_index', methods: ['GET'])]
    public function index(int $id, Request $request): Response
    {
        $project = $this->getProject($id);

        $tab    = $request->query->get('tab', 'authors');
        $kwType = $request->query->get('kwType', 'author');
        $kwView = $request->query->get('kwView', 'suggestions'); // 'suggestions' | 'all'
        $search = $request->query->get('q', '');

        $suggestions  = [];
        $allKeywords  = [];

        if ($tab === 'authors') {
            $suggestions = $this->normalizationService->findSimilarAuthors($project->getId());
        } elseif ($tab === 'keywords') {
            if ($kwView === 'all') {
                $allKeywords = $this->normalizationService->getAllKeywords($project->getId(), $kwType, $search);
            } else {
                $suggestions = $this->normalizationService->findSimilarKeywords($project->getId(), $kwType);
            }
        } elseif ($tab === 'duplicates') {
            $suggestions = $this->normalizationService->findPotentialDuplicates($project->getId());
        }

        return $this->render('normalize/index.html.twig', [
            'project'     => $project,
            'tab'         => $tab,
            'kwType'      => $kwType,
            'kwView'      => $kwView,
            'search'      => $search,
            'suggestions' => $suggestions,
            'allKeywords' => $allKeywords,
        ]);
    }

    #[Route('/merge', name: 'app_normalize_merge', methods: ['POST'])]
    public function merge(int $id, Request $request): Response
    {
        $project = $this->getProject($id);
        
        $type      = $request->request->get('type');
        $keepId    = (int) $request->request->get('keepId');
        $discardId = (int) $request->request->get('discardId');

        $isAjax    = $request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json';

        try {
            if ($type === 'author') {
                $this->normalizationService->mergeAuthors($keepId, $discardId);
            } elseif ($type === 'keyword') {
                $this->normalizationService->mergeKeywords($keepId, $discardId);
            } elseif ($type === 'document') {
                $this->normalizationService->mergeDocuments($keepId, $discardId);
            } else {
                throw new \InvalidArgumentException('Tipo de mesclagem inválido.');
            }

            $message = 'Mesclagem realizada com sucesso!';

            if ($isAjax) {
                return $this->json(['success' => true, 'message' => $message]);
            }

            $this->addFlash('success', $message);

        } catch (\Throwable $e) {
            $errMessage = 'Erro ao realizar mesclagem: ' . $e->getMessage();
            
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => $errMessage], 400);
            }

            $this->addFlash('danger', $errMessage);
        }

        return $this->redirectToRoute('app_normalize_index', [
            'id'     => $project->getId(),
            'tab'    => $request->request->get('tab', 'authors'),
            'kwType' => $request->request->get('kwType', 'author'),
        ]);
    }

    #[Route('/merge-batch', name: 'app_normalize_merge_batch', methods: ['POST'])]
    public function mergeBatch(int $id, Request $request): Response
    {
        $this->getProject($id);

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'message' => 'Payload inválido.'], 400);
        }

        $type  = $data['type']  ?? '';
        $pairs = $data['pairs'] ?? [];

        if (!is_array($pairs) || empty($pairs)) {
            return $this->json(['success' => false, 'message' => 'Nenhum par fornecido.'], 400);
        }

        try {
            $merged = match ($type) {
                'keyword' => $this->normalizationService->mergeKeywordsBatch($pairs),
                'author'  => $this->normalizationService->mergeAuthorsBatch($pairs),
                default   => throw new \InvalidArgumentException("Tipo '{$type}' não suportado em lote."),
            };

            return $this->json([
                'success' => true,
                'merged'  => $merged,
                'message' => "{$merged} item(s) mesclados com sucesso!",
            ]);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ── Export & Import Normalization Routes ──────────────────────────────────

    #[Route('/export/authors', name: 'app_normalize_export_authors', methods: ['GET'])]
    public function exportAuthors(int $id): Response
    {
        $project = $this->getProject($id);
        $rules = $this->normalizationService->exportAuthorNormalization($project->getId());

        $csv = Writer::createFromString('');
        $csv->insertOne(['preferred_name', 'variant_name', 'source', 'created_at']);

        foreach ($rules as $r) {
            $csv->insertOne([
                $r['preferred_name'] ?? '',
                $r['variant_name'] ?? '',
                $r['source'] ?? 'alternative',
                $r['created_at'] ?? '',
            ]);
        }

        $response = new Response($csv->toString());
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="normalizacao_autores_projeto_%d.csv"', $project->getId()));

        return $response;
    }

    #[Route('/import/authors', name: 'app_normalize_import_authors', methods: ['POST'])]
    public function importAuthors(int $id, Request $request): Response
    {
        $project = $this->getProject($id);

        if (!$this->isCsrfTokenValid('import_author_normalization', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_normalize_index', ['id' => $project->getId(), 'tab' => 'authors']);
        }

        $file = $request->files->get('csv_file');
        if (!$file) {
            $this->addFlash('danger', 'Por favor, envie um arquivo CSV.');
            return $this->redirectToRoute('app_normalize_index', ['id' => $project->getId(), 'tab' => 'authors']);
        }

        try {
            $csv = Reader::createFromPath($file->getRealPath(), 'r');
            $csv->setHeaderOffset(0);

            $records = iterator_to_array($csv->getRecords());
            $applied = $this->normalizationService->importAuthorNormalization($project->getId(), $records);

            $this->addFlash('success', "Importação de normatização de autores concluída! Processados: {$applied} par(es).");
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro ao importar normatização de autores: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_normalize_index', ['id' => $project->getId(), 'tab' => 'authors']);
    }

    #[Route('/export/keywords', name: 'app_normalize_export_keywords', methods: ['GET'])]
    public function exportKeywords(int $id): Response
    {
        $project = $this->getProject($id);
        $rules = $this->normalizationService->exportKeywordNormalization($project->getId());

        $csv = Writer::createFromString('');
        $csv->insertOne(['preferred_keyword', 'variant_keyword', 'keyword_type', 'status']);

        foreach ($rules as $r) {
            $csv->insertOne([
                $r['preferred_keyword'] ?? '',
                $r['variant_keyword'] ?? '',
                $r['keyword_type'] ?? 'author_keyword',
                $r['status'] ?? 1,
            ]);
        }

        $response = new Response($csv->toString());
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="normalizacao_palavras_chave_projeto_%d.csv"', $project->getId()));

        return $response;
    }

    #[Route('/import/keywords', name: 'app_normalize_import_keywords', methods: ['POST'])]
    public function importKeywords(int $id, Request $request): Response
    {
        $project = $this->getProject($id);

        if (!$this->isCsrfTokenValid('import_keyword_normalization', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_normalize_index', ['id' => $project->getId(), 'tab' => 'keywords']);
        }

        $file = $request->files->get('csv_file');
        if (!$file) {
            $this->addFlash('danger', 'Por favor, envie um arquivo CSV.');
            return $this->redirectToRoute('app_normalize_index', ['id' => $project->getId(), 'tab' => 'keywords']);
        }

        try {
            $csv = Reader::createFromPath($file->getRealPath(), 'r');
            $csv->setHeaderOffset(0);

            $records = iterator_to_array($csv->getRecords());
            $applied = $this->normalizationService->importKeywordNormalization($project->getId(), $records);

            $this->addFlash('success', "Importação de normatização de palavras-chave concluída! Processados: {$applied} par(es).");
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro ao importar normatização de palavras-chave: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_normalize_index', ['id' => $project->getId(), 'tab' => 'keywords']);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function getProject(int $id): BibliometricProject
    {
        $project = $this->em->getRepository(BibliometricProject::class)->find($id);
        if (!$project || $project->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        return $project;
    }
}
