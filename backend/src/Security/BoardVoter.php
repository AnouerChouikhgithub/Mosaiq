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

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Board
            && in_array($attribute, [self::VIEW, self::EDIT, self::COMMENT, self::SHARE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Board $board */
        $board = $subject;

        if ($user->getWorkspace()->getId() !== $board->getWorkspace()->getId()) {
            return false;
        }

        if ($user->canAdministerWorkspace()) {
            return true;
        }

        $role = $board->roleFor($user);

        return match ($attribute) {
            self::VIEW => null !== $role,
            self::COMMENT => in_array($role, ['Owner', 'Admin', 'Editor', 'Commenter'], true),
            self::EDIT => in_array($role, ['Owner', 'Admin', 'Editor'], true),
            self::SHARE => in_array($role, ['Owner', 'Admin'], true),
            default => false,
        };
    }
}
