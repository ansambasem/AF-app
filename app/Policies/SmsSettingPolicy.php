<?php

namespace App\Policies;

use App\Models\User;

/**
 * The SMS provider is the company's, and its credentials send messages in
 * the company's name: only the Super Admin sets it up.
 */
class SmsSettingPolicy
{
    public function manage(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
