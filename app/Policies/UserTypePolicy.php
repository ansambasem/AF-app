<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\User;
use App\Models\UserType;

class UserTypePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(PermissionKey::ViewUserTypes, PermissionKey::CreateUserTypes, PermissionKey::UpdateUserTypes);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, UserType $userType): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionKey::CreateUserTypes);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, UserType $userType): bool
    {
        return $user->hasPermission(PermissionKey::UpdateUserTypes);
    }

    /**
     * Deleting takes its own permission; only an unused record is
     * deleted (see deletionBlocker()).
     */
    public function delete(User $user, UserType $userType): bool
    {
        return $user->hasPermission(PermissionKey::DeleteUserTypes);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, UserType $userType): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, UserType $userType): bool
    {
        return false;
    }
}
