<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\CircuitBreaker;
use App\Models\User;

class CircuitBreakerPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(PermissionKey::ViewCircuitBreakers, PermissionKey::CreateCircuitBreakers, PermissionKey::UpdateCircuitBreakers);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, CircuitBreaker $circuitBreaker): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionKey::CreateCircuitBreakers);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, CircuitBreaker $circuitBreaker): bool
    {
        return $user->hasPermission(PermissionKey::UpdateCircuitBreakers);
    }

    /**
     * Deleting takes its own permission; only an unused record is
     * deleted (see deletionBlocker()).
     */
    public function delete(User $user, CircuitBreaker $circuitBreaker): bool
    {
        return $user->hasPermission(PermissionKey::DeleteCircuitBreakers);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, CircuitBreaker $circuitBreaker): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, CircuitBreaker $circuitBreaker): bool
    {
        return false;
    }
}
