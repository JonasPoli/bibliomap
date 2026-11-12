<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            $name = trim($request->request->get('name', ''));
            $email = trim($request->request->get('email', ''));
            $institution = trim($request->request->get('institution', ''));
            $country = trim($request->request->get('country', ''));
            $plainPassword = (string) $request->request->get('plainPassword', '');
            $confirmPassword = (string) $request->request->get('confirmPassword', '');
            $currentPassword = (string) $request->request->get('currentPassword', '');

            $hasError = false;

            if (!$this->isCsrfTokenValid('profile_edit', (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de segurança inválido. Recarregue a página e tente de novo.');
                return $this->redirectToRoute('app_profile_edit');
            }

            if ($name === '') {
                $this->addFlash('danger', 'O nome não pode estar em branco.');
                $hasError = true;
            }

            if ($email === '') {
                $this->addFlash('danger', 'O e-mail não pode estar em branco.');
                $hasError = true;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('danger', 'Por favor, informe um e-mail válido.');
                $hasError = true;
            } else {
                // Check if email is already in use by another user
                $existingUser = $em->getRepository(User::class)->findOneBy(['email' => $email]);
                if ($existingUser && $existingUser->getId() !== $user->getId()) {
                    $this->addFlash('danger', 'Este e-mail já está sendo utilizado por outro usuário.');
                    $hasError = true;
                }
            }

            // Password matching & validation if not blank
            if ($plainPassword !== '') {
                if (mb_strlen($plainPassword) < User::PASSWORD_MIN_LENGTH) {
                    $this->addFlash('danger', 'A nova senha deve ter no mínimo ' . User::PASSWORD_MIN_LENGTH . ' caracteres.');
                    $hasError = true;
                } elseif ($plainPassword !== $confirmPassword) {
                    $this->addFlash('danger', 'As senhas informadas não coincidem.');
                    $hasError = true;
                }
            }

            // Trocar o e-mail de acesso ou a senha exige a senha atual: sem isso, quem pegasse a
            // sessão aberta por um instante ficava com a conta.
            $changesCredentials = $plainPassword !== '' || ($email !== '' && $email !== $user->getEmail());
            if ($changesCredentials && !$hasError
                && ($currentPassword === '' || !$passwordHasher->isPasswordValid($user, $currentPassword))) {
                $this->addFlash('danger', 'Para alterar o e-mail ou a senha, informe corretamente a sua senha atual.');
                $hasError = true;
            }

            if (!$hasError) {
                $user->setName($name);
                $user->setEmail($email);
                $user->setInstitution($institution !== '' ? $institution : null);
                $user->setCountry($country !== '' ? $country : null);

                if ($plainPassword !== '') {
                    $hashedPassword = $passwordHasher->hashPassword($user, $plainPassword);
                    $user->setPassword($hashedPassword);
                }

                $em->flush();
                $this->addFlash('success', 'Perfil atualizado com sucesso!');

                return $this->redirectToRoute('app_profile_edit');
            }
        }

        return $this->render('profile/edit.html.twig', [
            'user' => $user,
        ]);
    }
}
