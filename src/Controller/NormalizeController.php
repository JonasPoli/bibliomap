<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Service\Normalize\NormalizationService;
use Doctrine\ORM\EntityManagerInterface;
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

        // Redirect back with current tab parameters intact
        return $this->redirectToRoute('app_normalize_index', [
            'id'     => $project->getId(),
            'tab'    => $request->request->get('tab', 'authors'),
            'kwType' => $request->request->get('kwType', 'author'),
        ]);
    }

    #[Route('/merge-batch', name: 'app_normalize_merge_batch', methods: ['POST'])]
    public function mergeBatch(int $id, Request $request): Response
    {
        $this->getProject($id); // access check

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
