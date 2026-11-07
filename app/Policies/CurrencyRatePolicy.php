<?php

namespace App\Policies;

use App\Models\User;

class CurrencyRatePolicy
{
    public function manage(User $user): bool
    {
        return $user->is_active && $user->isSuperAdmin();
    }
}
