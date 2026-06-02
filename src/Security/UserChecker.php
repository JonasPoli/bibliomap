<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Blocks login for users who are pending approval or inactive.
 * Registered in security.yaml under firewalls.main.user_checker.
 */
class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
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

    public function checkPostAuth(UserInterface $user): void
    {
        // No post-auth checks needed
    }
}
