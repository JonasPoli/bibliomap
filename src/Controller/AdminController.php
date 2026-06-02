<?php

namespace App\Controller;

use App\Entity\SiteSettings;
use App\Entity\User;
use App\Repository\SiteSettingsRepository;
use App\Repository\UserRepository;
use App\Service\SiteSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'app_admin_')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly UserRepository        $userRepo,
        private readonly EntityManagerInterface $em,
        private readonly SiteSettingsService   $settingsService,
        private readonly SiteSettingsRepository $settingsRepo,
    ) {}

    // ─── Dashboard ───────────────────────────────────────────────────────────

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $counts = $this->userRepo->countByStatus();
        $recent = $this->userRepo->findWithFilters(period: '24h', sort: 'newest');

        return $this->render('admin/index.html.twig', [
            'counts' => $counts,
            'recent' => $recent,
        ]);
    }

    // ─── User list ───────────────────────────────────────────────────────────

    #[Route('/usuarios', name: 'users')]
    public function users(Request $request): Response
    {
        $search = $request->query->getString('search', '');
        $status = $request->query->getString('status', 'all');
        $period = $request->query->getString('period', 'all');
        $sort   = $request->query->getString('sort', 'newest');

        $users  = $this->userRepo->findWithFilters($search, $status, $period, $sort);
        $counts = $this->userRepo->countByStatus();

        return $this->render('admin/users.html.twig', [
            'users'  => $users,
            'counts' => $counts,
            'search' => $search,
            'status' => $status,
            'period' => $period,
            'sort'   => $sort,
        ]);
    }

    // ─── Approve ─────────────────────────────────────────────────────────────

    #[Route('/usuarios/{id}/aprovar', name: 'user_approve', methods: ['POST'])]
    public function approve(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_user_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token inválido.');
            return $this->redirectToRoute('app_admin_users');
        }

        $user->setStatus(User::STATUS_ACTIVE);
        $this->em->flush();

        $this->addFlash('success', "Usuário <strong>{$user->getName()}</strong> aprovado com sucesso!");
        return $this->redirectToRoute('app_admin_users');
    }

    // ─── Reject / Inactivate ─────────────────────────────────────────────────

    #[Route('/usuarios/{id}/rejeitar', name: 'user_reject', methods: ['POST'])]
    public function reject(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_user_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token inválido.');
            return $this->redirectToRoute('app_admin_users');
        }

        $user->setStatus(User::STATUS_INACTIVE);
        $this->em->flush();

        $this->addFlash('warning', "Acesso de <strong>{$user->getName()}</strong> rejeitado/desativado.");
        return $this->redirectToRoute('app_admin_users');
    }

    // ─── Reactivate ──────────────────────────────────────────────────────────

    #[Route('/usuarios/{id}/ativar', name: 'user_activate', methods: ['POST'])]
    public function activate(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_user_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token inválido.');
            return $this->redirectToRoute('app_admin_users');
        }

        $user->setStatus(User::STATUS_ACTIVE);
        $this->em->flush();

        $this->addFlash('success', "Usuário <strong>{$user->getName()}</strong> reativado com sucesso!");
        return $this->redirectToRoute('app_admin_users');
    }

    // ─── Impersonation ───────────────────────────────────────────────────────

    #[Route('/usuarios/{id}/impersonar', name: 'user_impersonate')]
    public function impersonate(User $user): Response
    {
        if ($user->isPending() || $user->isInactive()) {
            $this->addFlash('danger', 'Não é possível impersonar um usuário inativo ou pendente.');
            return $this->redirectToRoute('app_admin_users');
        }

        return $this->redirectToRoute('app_projects_index', [
            '_switch_user' => $user->getEmail(),
        ]);
    }

    // ─── Exit impersonation ──────────────────────────────────────────────────

    #[Route('/sair-impersonacao', name: 'exit_impersonation')]
    public function exitImpersonation(): Response
    {
        return $this->redirectToRoute('app_admin_users', [
            '_switch_user' => '_exit',
        ]);
    }

    // ─── Site Settings ───────────────────────────────────────────────────────

    #[Route('/configuracoes', name: 'settings')]
    public function settings(Request $request): Response
    {
        $settings = $this->settingsService->get();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_settings', $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de segurança inválido.');
                return $this->redirectToRoute('app_admin_settings');
            }

            $settings->setGoogleAnalyticsId($request->request->get('googleAnalyticsId'));
            $settings->setSeoTitle((string) $request->request->get('seoTitle'));
            $settings->setSeoDescription((string) $request->request->get('seoDescription'));
            $settings->setSeoKeywords((string) $request->request->get('seoKeywords'));
            $settings->setBaseUrl((string) $request->request->get('baseUrl'));

            /** @var ?UploadedFile $ogImageFile */
            $ogImageFile = $request->files->get('ogImage');

            // Handle "remove image" checkbox
            if ($request->request->getBoolean('removeOgImage')) {
                $this->settingsService->removeOgImage($settings);
                $ogImageFile = null;
            }

            try {
                $this->settingsService->save($settings, $ogImageFile);
                $this->addFlash('success', 'Configurações salvas com sucesso!');
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'Erro ao salvar: ' . $e->getMessage());
            }

            return $this->redirectToRoute('app_admin_settings');
        }

        return $this->render('admin/settings.html.twig', [
            'settings' => $settings,
        ]);
    }
}
