<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\MeterBox;
use App\Models\User;

class MeterBoxPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(PermissionKey::ViewMeterBoxes, PermissionKey::CreateMeterBoxes, PermissionKey::UpdateMeterBoxes);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, MeterBox $meterBox): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return $user->isSuperAdmin() || $meterBox->branch_id === $user->workBranchId();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionKey::CreateMeterBoxes);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, MeterBox $meterBox): bool
    {
        if (! $user->hasPermission(PermissionKey::UpdateMeterBoxes)) {
            return false;
        }

        return $user->isSuperAdmin() || $meterBox->branch_id === $user->workBranchId();
    }

    /**
     * Deleting takes its own permission, for a meter box of the user's
     * own branch (any branch for the Super Admin).
     */
    public function delete(User $user, MeterBox $meterBox): bool
    {
        return $user->hasPermission(PermissionKey::DeleteMeterBoxes)
            && ($user->isSuperAdmin() || $meterBox->branch_id === $user->workBranchId());
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, MeterBox $meterBox): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, MeterBox $meterBox): bool
    {
        return false;
    }
}
