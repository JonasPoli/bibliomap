<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Entity\User;
use App\Repository\BibliometricProjectRepository;
use App\Repository\UserRepository;
use App\Service\ProjectCopyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/projetos', name: 'app_admin_projects_')]
#[IsGranted('ROLE_ADMIN')]
class AdminProjectController extends AbstractController
{
    public function __construct(
        private readonly BibliometricProjectRepository $projectRepo,
        private readonly UserRepository                $userRepo,
        private readonly ProjectCopyService            $copyService,
    ) {}

    /**
     * Lists all projects of a given user.
     */
    #[Route('/usuario/{id}', name: 'user', requirements: ['id' => '\d+'])]
    public function userProjects(User $user): Response
    {
        $projects = $this->projectRepo->findByUser($user->getId());
        $users    = $this->userRepo->findActiveUsersExcept($user->getId());

        return $this->render('admin/user_projects.html.twig', [
            'owner'    => $user,
            'projects' => $projects,
            'users'    => $users,
        ]);
    }

    /**
     * Copies a project to another user.
     * POST /admin/projetos/{id}/copiar  { target_user_id, _token }
     */
    #[Route('/{id}/copiar', name: 'copy', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function copy(BibliometricProject $project, Request $request): Response
    {
        $ownerId = $project->getUser()->getId();

        if (!$this->isCsrfTokenValid('copy_project_' . $project->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');
            return $this->redirectToRoute('app_admin_projects_user', ['id' => $ownerId]);
        }

        $targetUserId = (int) $request->request->get('target_user_id');
        $targetUser   = $this->userRepo->find($targetUserId);

        if (!$targetUser) {
            $this->addFlash('danger', 'Usuário de destino não encontrado.');
            return $this->redirectToRoute('app_admin_projects_user', ['id' => $ownerId]);
        }

        try {
            $newProject = $this->copyService->copy($project, $targetUser);

            $this->addFlash(
                'success',
                sprintf(
                    'Projeto <strong>"%s"</strong> copiado com sucesso para <strong>%s</strong>!',
                    $project->getTitle(),
                    $targetUser->getName(),
                ),
            );

            // Redirect to destination user's projects for confirmation
            return $this->redirectToRoute('app_admin_projects_user', ['id' => $targetUser->getId()]);

        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro ao copiar projeto: ' . $e->getMessage());
            return $this->redirectToRoute('app_admin_projects_user', ['id' => $ownerId]);
        }
    }
}
