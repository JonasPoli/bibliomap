<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
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
    ) {}

    #[Route('', name: 'app_thematic_map_index', methods: ['GET'])]
    public function index(int $id): Response
    {
        $project = $this->getProject($id);

        return $this->render('network/thematic_map.html.twig', [
            'project' => $project,
        ]);
    }

    #[Route('/data', name: 'app_thematic_map_data', methods: ['GET'])]
    public function data(int $id, Request $request): Response
    {
        $project   = $this->getProject($id);
        $kwType    = $request->query->get('kwType', 'author');
        $minOccur  = max((int)$request->query->get('minOccur', 2), 1);
        $limit     = min(max((int)$request->query->get('limit', 100), 10), 300);

        $data = $this->thematicMapService->buildThematicMap($project->getId(), $kwType, $minOccur, $limit);

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
