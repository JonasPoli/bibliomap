<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Repository\ClassificationGroupRepository;
use App\Service\Analytics\CsvCell;
use App\Service\Cache\AnalyticsCache;
use App\Service\Classification\GroupComparisonService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/projects/{id}/classification/compare', name: 'app_classification_compare_')]
#[IsGranted('ROLE_USER')]
class GroupComparisonController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClassificationGroupRepository $groupRepo,
        private readonly GroupComparisonService $comparisonService,
        private readonly AnalyticsCache $cache,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(BibliometricProject $project): Response
    {
        $this->denyAccessUnlessGranted('view', $project);

        $groups = $this->groupRepo->findByProject($project->getId());

        return $this->render('classification/compare.html.twig', [
            'project' => $project,
            'groups'  => $groups,
        ]);
    }

    #[Route('/data', name: 'data', methods: ['GET'])]
    public function data(BibliometricProject $project, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('view', $project);

        $groupIdsRaw = $request->query->get('groups', '');
        $groupIds = array_filter(array_map('intval', explode(',', $groupIdsRaw)));

        if (empty($groupIds)) {
            // Select all normal groups if none passed
            $allGroups = $this->groupRepo->findByProject($project->getId());
            foreach ($allGroups as $g) {
                if ($g->getType() === 'normal') {
                    $groupIds[] = $g->getId();
                }
            }
        }

        if (empty($groupIds)) {
            return new JsonResponse(['error' => 'Nenhum grupo selecionado para comparação.'], 400);
        }

        $groupIds = array_values(array_unique($groupIds));
        sort($groupIds);

        // Só grupos deste projeto entram na chave do cache (ids de fora não mudam o resultado e,
        // sem este filtro, cada combinação inventada criaria uma entrada nova)
        $projectGroupIds = array_map(fn($g) => $g->getId(), $this->groupRepo->findByProject($project->getId()));
        $cacheIds = array_values(array_intersect($groupIds, $projectGroupIds));
        if ($cacheIds !== []) {
            $groupIds = $cacheIds; // ids de outros projetos não somam nada nas consultas: mesmo resultado
        }

        // A comparação depende dos grupos escolhidos e dos resultados da classificação (gravados no
        // banco, o que muda a versão dos dados e invalida o resultado guardado)
        $compute = fn() => [
            'summary'      => $this->comparisonService->getGroupSummaryComparison($project->getId(), $groupIds),
            'temporal'     => $this->comparisonService->getTemporalEvolutionComparison($project->getId(), $groupIds),
            'overlap'      => $this->comparisonService->getSharedKeywordOverlap($project->getId(), $groupIds, false),
            'geographic'   => $this->comparisonService->getGeographicProfileComparison($project->getId(), $groupIds),
            'institutions' => $this->comparisonService->getInstitutionalProfileComparison($project->getId(), $groupIds),
            'qualis'       => $this->comparisonService->getQualisImpactComparison($project->getId(), $groupIds),
        ];
        $payload = $cacheIds === [] ? $compute() : $this->cache->get($project->getId(), 'group_comparison.data', ['groups' => $groupIds], $compute);

        return new JsonResponse($payload);
    }

    #[Route('/export-csv', name: 'export_csv', methods: ['GET'])]
    public function exportCsv(BibliometricProject $project, Request $request): Response
    {
        $this->denyAccessUnlessGranted('view', $project);

        $groupIdsRaw = $request->query->get('groups', '');
        $groupIds = array_filter(array_map('intval', explode(',', $groupIdsRaw)));

        if (empty($groupIds)) {
            $allGroups = $this->groupRepo->findByProject($project->getId());
            foreach ($allGroups as $g) {
                if ($g->getType() === 'normal') {
                    $groupIds[] = $g->getId();
                }
            }
        }

        $summary = $this->comparisonService->getGroupSummaryComparison($project->getId(), $groupIds);

        $csv = "\xEF\xBB\xBF"; // UTF-8 BOM
        $csv .= "ID Grupo;Nome do Grupo;Tipo;Total Documentos;% do Corpus;Total Citações;Média Citações\r\n";

        foreach ($summary['groups'] as $g) {
            $csv .= sprintf(
                '%d;"%s";"%s";%d;%.1f;%d;%.2f' . "\r\n",
                $g['group_id'],
                str_replace('"', '""', CsvCell::escape($g['group_name'])),
                $g['type'],
                $g['doc_count'],
                $g['percentage'],
                $g['total_citations'],
                $g['avg_citations']
            );
        }

        $filename = 'comparativo_grupos_' . $project->getId() . '_' . date('Ymd') . '.csv';

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ]);
    }
}
