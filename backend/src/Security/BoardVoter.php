<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Board;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Board>
 */
final class BoardVoter extends Voter
{
    public const VIEW = 'BOARD_VIEW';
    public const EDIT = 'BOARD_EDIT';
    public const COMMENT = 'BOARD_COMMENT';
    public const SHARE = 'BOARD_SHARE';
    public const MANAGE = 'BOARD_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Board
            && in_array($attribute, [self::VIEW, self::EDIT, self::COMMENT, self::SHARE, self::MANAGE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Board $board */
        $board = $subject;

        if (!$user->hasActiveWorkspaceMembership() || $user->getWorkspace()->getId() !== $board->getWorkspace()->getId()) {
            return false;
        }

        $role = $board->roleFor($user);
        $canManage = $user->canAdministerWorkspace() || in_array($role, ['Owner', 'Admin'], true);

        // Lifecycle management is deliberately separate from content access: a
        // board manager can restore a trashed board without being able to read it.
        if (self::MANAGE === $attribute) {
            return $canManage;
        }

        if ($board->isTrashed()) {
            return false;
        }

        if ($board->isArchived()) {
            return self::VIEW === $attribute && ($user->canAdministerWorkspace() || null !== $role);
        }

        if ($user->canAdministerWorkspace()) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => null !== $role,
            self::COMMENT => in_array($role, ['Owner', 'Admin', 'Editor', 'Commenter'], true),
            self::EDIT => in_array($role, ['Owner', 'Admin', 'Editor'], true),
            self::SHARE => in_array($role, ['Owner', 'Admin'], true),
            default => false,
        };
    }
}
