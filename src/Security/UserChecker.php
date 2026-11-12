<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Recusa o login de usuário pendente de aprovação ou inativo.
 * Registrado em security.yaml (firewalls.main.user_checker).
 *
 * A conferência fica em checkPostAuth, depois da senha: em checkPreAuth a mensagem de status
 * aparecia para qualquer senha, o que revelava a quem não tem a senha se um e-mail tem conta
 * e em que situação ela está. O checkPostAuth também roda no remember-me e na impersonação.
 */
class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        // Nada antes da senha (ver comentário da classe)
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if ($user->isPending()) {
            throw new CustomUserMessageAccountStatusException(
                'Sua conta está aguardando aprovação do administrador. ' .
                'Você será notificado quando o acesso for liberado.'
            );
        }

        if ($user->isInactive()) {
            throw new CustomUserMessageAccountStatusException(
                'Sua conta foi desativada. Entre em contato com o administrador.'
            );
        }
    }
}
