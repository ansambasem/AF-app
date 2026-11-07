<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\TariffSegment;
use App\Models\User;

/**
 * Segments are managed on the Tariffs page, so they follow the tariff
 * permissions: whoever may add or edit tariffs may do the same to segments.
 */
class TariffSegmentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(PermissionKey::ViewTariffs, PermissionKey::CreateTariffs, PermissionKey::UpdateTariffs);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, TariffSegment $tariffSegment): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionKey::CreateTariffs);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, TariffSegment $tariffSegment): bool
    {
        return $user->hasPermission(PermissionKey::UpdateTariffs);
    }

    /**
     * Deleting takes its own permission; only an unused record is
     * deleted (see deletionBlocker()).
     */
    public function delete(User $user, TariffSegment $tariffSegment): bool
    {
        return $user->hasPermission(PermissionKey::DeleteTariffs);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, TariffSegment $tariffSegment): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, TariffSegment $tariffSegment): bool
    {
        return false;
    }
}
