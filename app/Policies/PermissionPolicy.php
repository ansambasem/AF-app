<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\User;

class PermissionPolicy
{
    /**
     * Delegates need an explicit grant; the controller restricts them to
     * ordinary employees of their own branch and permissions they may grant.
     */
    public function manage(User $user): bool
    {
        return $user->hasPermission(PermissionKey::ManagePermissions);
    }
}
