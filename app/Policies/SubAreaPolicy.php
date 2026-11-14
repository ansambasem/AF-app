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
     * Determine whether the user can update the model: a Super Admin any
     * sub-area; anyone else with the permission only those in their own
     * branch's area.
     */
    public function update(User $user, SubArea $subArea): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission(PermissionKey::UpdateSubAreas)
            && $subArea->area_id !== null
            && (int) $subArea->area_id === $user->branchAreaId();
    }

    /**
     * Deleting takes its own permission, for a sub-area of the user's
     * branch's area (any for the Super Admin).
     */
    public function delete(User $user, SubArea $subArea): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission(PermissionKey::DeleteSubAreas)
            && $subArea->area_id !== null
            && (int) $subArea->area_id === $user->branchAreaId();
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
}
