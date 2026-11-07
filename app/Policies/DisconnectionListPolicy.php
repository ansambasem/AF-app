<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\DisconnectionList;
use App\Models\User;

/**
 * Each branch keeps its own disconnection lists: staff work only on their
 * branch's (the Super Admin on any). Viewing, managing the list, warning and
 * confirming a disconnection each take their own permission.
 */
class DisconnectionListPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(
            PermissionKey::ViewDisconnections,
            PermissionKey::ManageDisconnections,
            PermissionKey::WarnDisconnections,
            PermissionKey::ExecuteDisconnections,
        );
    }

    public function view(User $user, DisconnectionList $list): bool
    {
        return $this->viewAny($user) && $this->inBranch($user, $list);
    }

    /**
     * Opening a list for a branch: the user's own (any for the Super Admin).
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionKey::ManageDisconnections) && ($user->isSuperAdmin() || $user->workBranchId() !== null);
    }

    /**
     * Renaming or closing the list, adding and removing subscriptions, and
     * setting or extending their dates.
     */
    public function manage(User $user, DisconnectionList $list): bool
    {
        return $user->hasPermission(PermissionKey::ManageDisconnections) && $this->inBranch($user, $list);
    }

    /**
     * Sending warnings, which also shows the subscriptions' phone numbers.
     */
    public function warn(User $user, DisconnectionList $list): bool
    {
        return $user->hasPermission(PermissionKey::WarnDisconnections) && $this->inBranch($user, $list);
    }

    /**
     * Confirming a disconnection carried out, which disconnects the service
     * and charges the disconnection fee.
     */
    public function execute(User $user, DisconnectionList $list): bool
    {
        return $user->hasPermission(PermissionKey::ExecuteDisconnections) && $this->inBranch($user, $list);
    }

    private function inBranch(User $user, DisconnectionList $list): bool
    {
        return $user->isSuperAdmin() || $list->branch_id === $user->workBranchId();
    }
}
