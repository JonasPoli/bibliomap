<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Service\Analytics\ThreeFieldsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/projects/{id}/three-fields-plot')]
#[IsGranted('ROLE_USER')]
class ThreeFieldsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ThreeFieldsService $threeFieldsService,
    ) {}

    #[Route('', name: 'app_three_fields_plot', methods: ['GET'])]
    public function index(int $id): Response
    {
        $project = $this->getProject($id);

        return $this->render('report/three_fields.html.twig', [
            'project' => $project,
        ]);
    }

    #[Route('/data', name: 'app_three_fields_data', methods: ['GET'])]
    public function data(int $id, Request $request): Response
    {
        $project   = $this->getProject($id);
        $left      = $request->query->get('left', 'country');
        $middle    = $request->query->get('middle', 'keyword_author');
        $right     = $request->query->get('right', 'source');
        $limit     = min(max((int)$request->query->get('limit', 20), 5), 100);
        $minWeight = max((int)$request->query->get('minWeight', 1), 1);

        $allowedFields = ['author', 'keyword_author', 'keyword_indexed', 'source', 'country', 'institution'];
        if (!in_array($left, $allowedFields) || !in_array($middle, $allowedFields) || !in_array($right, $allowedFields)) {
            return $this->json(['error' => 'Campos inválidos especificados.'], 400);
        }

        $data = $this->threeFieldsService->buildThreeFieldsPlot(
            $project->getId(),
            $left,
            $middle,
            $right,
            $limit,
            $minWeight
        );

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
