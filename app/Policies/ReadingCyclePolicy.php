<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\ReadingCycle;
use App\Models\User;

/**
 * Each branch prepares its own reading cycles: staff work only on their
 * branch's (the Super Admin on any). Allocating boxes, approving the
 * allocation, printing paper sheets and reviewing held field readings each
 * take their own permission.
 */
class ReadingCyclePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(
            PermissionKey::ViewReadingAssignments,
            PermissionKey::ManageReadingAssignments,
            PermissionKey::ApproveReadingAssignments,
            PermissionKey::PrintReadingSheets,
            PermissionKey::ReviewReadingSubmissions,
        );
    }

    public function view(User $user, ReadingCycle $cycle): bool
    {
        return $this->viewAny($user) && $this->inBranch($user, $cycle);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionKey::ManageReadingAssignments) && ($user->isSuperAdmin() || $user->workBranchId() !== null);
    }

    /**
     * Allocating the cycle's boxes, copying the previous cycle's allocation,
     * and setting its reading day or closing it.
     */
    public function manage(User $user, ReadingCycle $cycle): bool
    {
        return $user->hasPermission(PermissionKey::ManageReadingAssignments) && $this->inBranch($user, $cycle);
    }

    /**
     * Approving allocations, which lets readers download them.
     */
    public function approve(User $user, ReadingCycle $cycle): bool
    {
        return $user->hasPermission(PermissionKey::ApproveReadingAssignments) && $this->inBranch($user, $cycle);
    }

    public function printSheets(User $user, ReadingCycle $cycle): bool
    {
        return $user->hasPermission(PermissionKey::PrintReadingSheets) && $this->inBranch($user, $cycle);
    }

    /**
     * Saving or refusing field readings held for review.
     */
    public function review(User $user, ReadingCycle $cycle): bool
    {
        return $user->hasPermission(PermissionKey::ReviewReadingSubmissions) && $this->inBranch($user, $cycle);
    }

    private function inBranch(User $user, ReadingCycle $cycle): bool
    {
        return $user->isSuperAdmin() || $cycle->branch_id === $user->workBranchId();
    }
}
