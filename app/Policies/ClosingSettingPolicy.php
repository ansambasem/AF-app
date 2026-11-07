<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\User;
use App\Support\BranchDomainContext;

class ClosingSettingPolicy extends BranchSchedulePolicy
{
    public function manageAny(User $user): bool
    {
        return $user->is_active && $user->hasPermission(PermissionKey::ManageBoxPeriods)
            && ($user->isSuperAdmin() || $user->workBranchId() !== null);
    }

    public function manage(User $user, ?Branch $branch = null): bool
    {
        if (BranchDomainContext::id() !== null && $branch?->id !== BranchDomainContext::id()) {
            return false;
        }

        return $this->manageAny($user) && ($user->isSuperAdmin() || ($branch !== null && $user->workBranchId() === $branch->id));
    }
}
