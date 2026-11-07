<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\MessageTemplate;
use App\Models\User;

/**
 * Saved wordings are shared by the whole company; whoever may send
 * messages may add, change or remove them.
 */
class MessageTemplatePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionKey::SendMessages);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionKey::SendMessages);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, MessageTemplate $template): bool
    {
        return $user->hasPermission(PermissionKey::SendMessages);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, MessageTemplate $template): bool
    {
        return $user->hasPermission(PermissionKey::SendMessages);
    }
}
