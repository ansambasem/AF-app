<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\User;

class UserPolicy
{
    /**
     * Whether the user's ticked permissions let them work with other users
     * at all, before branch scoping is considered.
     */
    private function hasBaseAccess(User $user): bool
    {
        return $user->hasAnyPermission(PermissionKey::ViewUsers, PermissionKey::CreateUsers, PermissionKey::UpdateUsers);
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasBaseAccess($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->hasBaseAccess($user) && $model->branch_id === $user->workBranchId();
    }

    /**
     * Determine whether the user can create models.
     *
     * Anyone with the "Add Users" permission may create a user — for
     * anyone but a Super Admin always restricted to the branch-level staff
     * roles and to their own branch, forced server-side in
     * StoreUserRequest and UserController::store() regardless of what's
     * submitted.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionKey::CreateUsers);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission(PermissionKey::UpdateUsers)
            && ! $model->isProtectedFromBranchManagement()
            && $model->branch_id === $user->workBranchId();
    }

    /** Only administrators manage passwords; branch administrators manage their own staff. */
    public function updatePassword(User $user, User $model): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return ! $user->is($model)
            && $user->hasPermission(PermissionKey::ManageUserPasswords)
            && $this->update($user, $model);
    }

    /**
     * Deleting takes its own permission, on the same terms as editing,
     * and never reaches the user's own account or a Super Admin.
     */
    public function delete(User $user, User $model): bool
    {
        if ($user->is($model) || $model->isSuperAdmin()) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission(PermissionKey::DeleteUsers)
            && ! $model->isProtectedFromBranchManagement()
            && $model->branch_id === $user->workBranchId();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}
