<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\User;
use App\Support\BranchDomainContext;

/**
 * Who may set a schedule a branch can have its own of (closing, reading): the
 * Super Admin for every branch and for the company's default, and an
 * explicitly authorised employee for their own branch only.
 */
abstract class BranchSchedulePolicy
{
    /**
     * Whether the user sets some schedule at all: the company's or a branch's.
     */
    public function manageAny(User $user): bool
    {
        return $user->isSuperAdmin() || ($user->hasPermission(PermissionKey::ManageBranchSchedules) && $user->workBranchId() !== null);
    }

    /**
     * Whether the user sets the branch's schedule, or the company's default
     * when no branch is given.
     */
    public function manage(User $user, ?Branch $branch = null): bool
    {
        if (BranchDomainContext::id() !== null && $branch?->id !== BranchDomainContext::id()) {
            return false;
        }

        return $user->isSuperAdmin()
            || ($branch !== null && $user->hasPermission(PermissionKey::ManageBranchSchedules) && $user->workBranchId() === $branch->id);
    }
}
