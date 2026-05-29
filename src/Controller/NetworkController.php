<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Service\Network\NetworkService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/projects/{id}/networks')]
#[IsGranted('ROLE_USER')]
class NetworkController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NetworkService $networkService,
    ) {}

    #[Route('', name: 'app_networks_index', methods: ['GET'])]
    public function index(int $id): Response
    {
        $project = $this->getProject($id);

        return $this->render('network/index.html.twig', [
            'project' => $project,
        ]);
    }

    #[Route('/data/{type}', name: 'app_networks_data', methods: ['GET'])]
    public function data(int $id, string $type, Request $request): Response
    {
        $project   = $this->getProject($id);
        $minWeight = max((int)$request->query->get('minWeight', 1), 1);
        $limit     = min(max((int)$request->query->get('limit', 100), 10), 300);
        $kwType    = $request->query->get('kwType', 'author');

        $data = match ($type) {
            'coauthorship' => $this->networkService->coauthorship($project->getId(), $minWeight, $limit),
            'keywords'     => $this->networkService->keywords($project->getId(), $kwType, $minWeight, $limit),
            'countries'    => $this->networkService->countries($project->getId(), $minWeight, $limit),
            default        => throw $this->createNotFoundException('Tipo de rede inválido.')
        };

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
