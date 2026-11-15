<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Entity\WorkspaceInvitation;

/**
 * Single source of truth for workspace membership administration.
 *
 * Sprint 2 keeps one Owner per workspace. Ownership transfer and member
 * self-leave are not approved workflows; both are deliberately rejected here
 * rather than approximated by ad-hoc controller checks.
 */
final class WorkspaceMembershipPolicy
{
    public function canViewMembers(User $actor): bool
    {
        return $actor->hasActiveWorkspaceMembership();
    }

    public function canManageMembers(User $actor): bool
    {
        return $actor->canAdministerWorkspace();
    }

    public function canUpdateSettings(User $actor): bool
    {
        return $actor->canAdministerWorkspace();
    }

    /** @return list<string> */
    public function invitableRoles(User $actor): array
    {
        if (!$this->canManageMembers($actor)) {
            return [];
        }

        return 'Owner' === $actor->getRole()
            ? WorkspaceInvitation::ROLES
            : User::MEMBER_ROLES;
    }

    public function canInvite(User $actor, string $role): bool
    {
        return in_array($role, $this->invitableRoles($actor), true);
    }

    public function canChangeRole(User $actor, User $target, string $role): bool
    {
        if (!$this->isManageableTarget($actor, $target)) {
            return false;
        }
        if ('Owner' === $target->getRole()) {
            return false;
        }
        if (!in_array($role, User::ROLES, true) || 'Owner' === $role) {
            return false;
        }
        if ('Owner' === $actor->getRole()) {
            return true;
        }

        // Admins may only manage Member roles, and may not promote anyone to Admin.
        return in_array($target->getRole(), User::MEMBER_ROLES, true)
            && in_array($role, User::MEMBER_ROLES, true);
    }

    public function canRemove(User $actor, User $target): bool
    {
        if (!$this->isManageableTarget($actor, $target)) {
            return false;
        }
        if ('Owner' === $target->getRole()) {
            return false;
        }
        if ('Owner' === $actor->getRole()) {
            return true;
        }

        return in_array($target->getRole(), User::MEMBER_ROLES, true);
    }

    private function isManageableTarget(User $actor, User $target): bool
    {
        return $this->canManageMembers($actor)
            && $target->hasActiveWorkspaceMembership()
            && $actor->getId() !== $target->getId()
            && $actor->getWorkspace()->getId() === $target->getWorkspace()->getId();
    }
}
