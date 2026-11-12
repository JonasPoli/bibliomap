<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Service\Cache\AnalyticsCache;
use App\Service\Network\ThematicMapService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/projects/{id}/thematic-map')]
#[IsGranted('ROLE_USER')]
class ThematicMapController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ThematicMapService $thematicMapService,
        private readonly AnalyticsCache $cache,
    ) {}

    #[Route('', name: 'app_thematic_map_index', methods: ['GET'])]
    public function index(int $id): Response
    {
        $project = $this->getProject($id);

        // Anos do corpus, só como dica nos campos do recorte por período
        $range = $this->em->getConnection()->fetchAssociative(
            'SELECT MIN(year) AS y_min, MAX(year) AS y_max FROM document WHERE project_id = ? AND year IS NOT NULL AND year > 0',
            [$project->getId()]
        ) ?: [];

        return $this->render('network/thematic_map.html.twig', [
            'project' => $project,
            'yearMin' => $range['y_min'] ?? null,
            'yearMax' => $range['y_max'] ?? null,
        ]);
    }

    #[Route('/data', name: 'app_thematic_map_data', methods: ['GET'])]
    public function data(int $id, Request $request): Response
    {
        $project   = $this->getProject($id);
        $kwType    = $request->query->get('kwType', 'author');
        $minOccur  = max((int)$request->query->get('minOccur', 2), 1);
        $limit     = min(max((int)$request->query->get('limit', 100), 10), 300);

        // Recorte por período (anos inclusivos); campo vazio = sem limite daquele lado
        $yearFrom = self::yearParam($request->query->get('yearFrom'));
        $yearTo   = self::yearParam($request->query->get('yearTo'));
        if ($yearFrom !== null && $yearTo !== null && $yearFrom > $yearTo) {
            [$yearFrom, $yearTo] = [$yearTo, $yearFrom];
        }

        $compute = fn() => $this->thematicMapService->buildThematicMap($project->getId(), $kwType, $minOccur, $limit, $yearFrom, $yearTo);
        // kwType fora da lista conhecida não vai para o cache (parâmetro livre = entrada nova a cada texto)
        $data = in_array($kwType, ['author', 'indexed'], true)
            ? $this->cache->get($project->getId(), 'thematic_map', [
                'kwType' => $kwType, 'minOccur' => $minOccur, 'limit' => $limit, 'yearFrom' => $yearFrom, 'yearTo' => $yearTo,
            ], $compute)
            : $compute();

        return $this->json($data);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /** Ano de um campo de recorte: vazio ou fora de 1000–2200 = sem limite. */
    public static function yearParam(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }
        $year = (int) $raw;

        return $year >= 1000 && $year <= 2200 ? $year : null;
    }

    private function getProject(int $id): BibliometricProject
    {
        $project = $this->em->getRepository(BibliometricProject::class)->find($id);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        // Leitura: dono ou projeto público (ProjectVoter). Ações de escrita checam 'edit'.
        $this->denyAccessUnlessGranted('view', $project);
        return $project;
    }
}
