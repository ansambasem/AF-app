<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\MessageBatch;
use App\Models\User;

class MessageBatchPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(PermissionKey::ViewMessages, PermissionKey::SendMessages);
    }

    /**
     * A send to their own branch's subscriptions; a Super Admin sees every one.
     */
    public function view(User $user, MessageBatch $batch): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->viewAny($user) && $batch->branch_id !== null && $batch->branch_id === $user->workBranchId();
    }

    /**
     * Writing to subscriptions takes its own permission.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionKey::SendMessages);
    }

    /**
     * Sending a batch's messages again, or marking a WhatsApp message as sent.
     */
    public function update(User $user, MessageBatch $batch): bool
    {
        return $this->create($user) && $this->view($user, $batch);
    }

    /**
     * Sends stay as the record of what subscriptions were told.
     */
    public function delete(User $user, MessageBatch $batch): bool
    {
        return false;
    }
}
