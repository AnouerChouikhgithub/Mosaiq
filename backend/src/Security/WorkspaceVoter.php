<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Entity\Workspace;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Workspace>
 */
final class WorkspaceVoter extends Voter
{
    public const VIEW = 'WORKSPACE_VIEW';
    public const ADMINISTER = 'WORKSPACE_ADMINISTER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Workspace
            && in_array($attribute, [self::VIEW, self::ADMINISTER], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Workspace $workspace */
        $workspace = $subject;

        if ($user->getWorkspace()->getId() !== $workspace->getId()) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => true,
            self::ADMINISTER => $user->canAdministerWorkspace(),
            default => false,
        };
    }
}
