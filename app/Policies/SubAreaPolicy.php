<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\SubArea;
use App\Models\User;

class SubAreaPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(PermissionKey::ViewSubAreas, PermissionKey::CreateSubAreas, PermissionKey::UpdateSubAreas);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SubArea $subArea): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Whether the user may add an area, to `$branch` when one is given: a
     * Super Admin to any branch; anyone else with the permission only to
     * the branch they work in.
     */
    public function create(User $user, ?Branch $branch = null): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission(PermissionKey::CreateSubAreas)
            && $user->workBranchId() !== null
            && ($branch === null || $branch->id === $user->workBranchId());
    }

    /**
     * Renaming an area: a Super Admin any; anyone else with the permission
     * only those of the branch they work in.
     */
    public function update(User $user, SubArea $subArea): bool
    {
        return $user->isSuperAdmin() || ($user->hasPermission(PermissionKey::UpdateSubAreas) && $this->ofTheirBranch($user, $subArea));
    }

    /** Deleting takes its own permission, for an area of the user's branch (any for the Super Admin). */
    public function delete(User $user, SubArea $subArea): bool
    {
        return $user->isSuperAdmin() || ($user->hasPermission(PermissionKey::DeleteSubAreas) && $this->ofTheirBranch($user, $subArea));
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SubArea $subArea): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SubArea $subArea): bool
    {
        return false;
    }

    private function ofTheirBranch(User $user, SubArea $subArea): bool
    {
        return $subArea->branch_id !== null && $user->workBranchId() !== null && (int) $subArea->branch_id === $user->workBranchId();
    }
}
