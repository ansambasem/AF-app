<?php

namespace App\Policies;

use App\Models\User;

class BankPolicy
{
    public function manage(User $user): bool
    {
        return $user->is_active && $user->isSuperAdmin();
    }
}
