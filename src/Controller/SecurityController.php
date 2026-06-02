<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            /** @var User $user */
            $user = $this->getUser();
            if ($user->isPending()) {
                return $this->redirectToRoute('app_cadastro_pendente');
            }
            return $this->redirectToRoute('app_projects_index');
        }

        $error        = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error'         => $error,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // Handled by Symfony security
    }

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_projects_index');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $hashedPassword = $passwordHasher->hashPassword(
                $user,
                $form->get('plainPassword')->getData()
            );
            $user->setPassword($hashedPassword);
            $user->setRoles(['ROLE_USER']);
            $user->setStatus(User::STATUS_PENDING);

            $em->persist($user);
            $em->flush();

            return $this->redirectToRoute('app_cadastro_pendente');
        }

        return $this->render('security/register.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/cadastro-pendente', name: 'app_cadastro_pendente')]
    public function pending(): Response
    {
        return $this->render('security/pending.html.twig');
    }

    /**
     * Exits switch_user impersonation.
     * Must live OUTSIDE AdminController so that users being impersonated
     * (who lack ROLE_ADMIN) can still reach this URL.
     * Symfony's SwitchUserListener processes _switch_user=_exit before
     * any authorization check, restoring the original admin session.
     */
    #[Route('/sair-impersonacao', name: 'app_exit_impersonation')]
    #[IsGranted('ROLE_PREVIOUS_ADMIN')]
    public function exitImpersonation(): Response
    {
        // The _switch_user=_exit query param is handled by Symfony's firewall
        // listener — it restores the admin before the target route is rendered.
        return $this->redirectToRoute('app_admin_users', [
            '_switch_user' => '_exit',
        ]);
    }
}
