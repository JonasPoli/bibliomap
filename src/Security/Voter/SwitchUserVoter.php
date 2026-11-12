<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Decide quem pode entrar como outro usuário (switch_user do firewall main).
 *
 * - O alvo precisa estar ativo (pendente e inativo não entram nem pelo parâmetro direto na URL).
 * - Super administrador entra como qualquer outro usuário.
 * - Administrador comum só entra como usuário comum: sem isso, bastaria entrar como o super
 *   administrador para ganhar o papel dele.
 *
 * @extends Voter<string, User>
 */
class SwitchUserVoter extends Voter
{
    public const ATTRIBUTE = 'CAN_SWITCH_USER';

    public function __construct(private readonly RoleHierarchyInterface $roleHierarchy) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $actor = $token->getUser();
        if (!$actor instanceof User) {
            return false;
        }

        /** @var User $target */
        $target = $subject;

        if (!$target->isActive() || $target->getUserIdentifier() === $actor->getUserIdentifier()) {
            return false;
        }

        $actorRoles  = $this->roleHierarchy->getReachableRoleNames($token->getRoleNames());
        $targetRoles = $this->roleHierarchy->getReachableRoleNames($target->getRoles());

        if (in_array('ROLE_SUPER_ADMIN', $actorRoles, true)) {
            return true;
        }

        return in_array('ROLE_ADMIN', $actorRoles, true)
            && !in_array('ROLE_ADMIN', $targetRoles, true);
    }
}
