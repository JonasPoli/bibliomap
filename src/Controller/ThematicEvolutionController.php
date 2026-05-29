<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Service\Network\ThematicEvolutionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/projects/{id}/thematic-evolution')]
#[IsGranted('ROLE_USER')]
class ThematicEvolutionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ThematicEvolutionService $evolutionService,
    ) {}

    #[Route('', name: 'app_thematic_evolution_index', methods: ['GET'])]
    public function index(int $id): Response
    {
        $project = $this->getProject($id);
        $conn = $this->em->getConnection();

        // Dynamically fetch min and max years from the project's documents to bound the cutoff slider
        $years = $conn->fetchAssociative('
            SELECT MIN(year) as min_y, MAX(year) as max_y 
            FROM document 
            WHERE project_id = ? AND year IS NOT NULL
        ', [$project->getId()]);

        $minYear = $years['min_y'] ? (int)$years['min_y'] : 2021;
        $maxYear = $years['max_y'] ? (int)$years['max_y'] : 2027;

        // If min and max years are equal, make max at least min + 1
        if ($minYear === $maxYear) {
            $maxYear = $minYear + 1;
        }

        // Set default cutoff year in the middle of the interval
        $defaultCutoff = (int)round(($minYear + $maxYear) / 2);

        return $this->render('network/thematic_evolution.html.twig', [
            'project' => $project,
            'minYear' => $minYear,
            'maxYear' => $maxYear,
            'defaultCutoff' => $defaultCutoff,
        ]);
    }

    #[Route('/data', name: 'app_thematic_evolution_data', methods: ['GET'])]
    public function data(int $id, Request $request): Response
    {
        $project    = $this->getProject($id);
        $kwType     = $request->query->get('kwType', 'author');
        $cutoffYear = (int)$request->query->get('cutoffYear', 2024);
        $minOccur   = max((int)$request->query->get('minOccur', 2), 1);
        $limit      = min(max((int)$request->query->get('limit', 100), 10), 300);

        $data = $this->evolutionService->buildThematicEvolution($project->getId(), $kwType, $cutoffYear, $minOccur, $limit);

        return $this->json($data);
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
