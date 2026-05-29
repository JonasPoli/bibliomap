<?php

namespace App\Security\Voter;

use App\Entity\BibliometricProject;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, BibliometricProject>
 */
class ProjectVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, ['view', 'edit', 'delete'])
            && $subject instanceof BibliometricProject;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var BibliometricProject $project */
        $project = $subject;

        return match ($attribute) {
            'view' => $project->getUser() === $user || $project->isPublic(),
            'edit', 'delete' => $project->getUser() === $user,
            default => false,
        };
    }
}
