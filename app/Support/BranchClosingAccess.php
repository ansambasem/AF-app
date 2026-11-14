<?php

namespace App\Support;

use App\Enums\PermissionKey;
use App\Models\ClosingSetting;
use App\Models\User;

class BranchClosingAccess
{
    public static function view(User $user): bool
    {
        return $user->is_active && ($user->isSuperAdmin() || self::audit($user) || ($user->workBranchId() && ($user->hasPermission(PermissionKey::ManageBranchClosings) || $user->hasPermission(PermissionKey::CloseCashBoxes) || in_array($user->id, ClosingSetting::forBranch($user->workBranchId())->receivers ?? [], true) || in_array($user->id, ClosingSetting::forBranch($user->workBranchId())->approvers ?? [], true))));
    }

    public static function manage(User $user, int $branchId): bool
    {
        return $user->is_active && ($user->isSuperAdmin() || ($user->hasPermission(PermissionKey::ManageBranchClosings) && $user->workBranchId() === $branchId));
    }

    /**
     * Whether the user may close, count and receive the branch's boxes: the
     * branch admin and approvers, anyone given the "Close Cash Boxes"
     * permission, and the receivers named in the closing settings.
     */
    public static function receive(User $user, int $branchId): bool
    {
        return self::approve($user, $branchId) || ($user->is_active && $user->workBranchId() === $branchId
            && ($user->hasPermission(PermissionKey::CloseCashBoxes) || in_array($user->id, ClosingSetting::forBranch($branchId)->receivers ?? [], true)));
    }

    public static function audit(User $user): bool
    {
        return $user->is_active && ($user->hasFinancialAuditAccess() || ($user->hasPermission(PermissionKey::ViewAllClosings)));
    }

    public static function closedOnly(User $user, int $branchId): bool
    {
        return self::audit($user) && ! self::receive($user, $branchId);
    }

    public static function approve(User $user, int $branchId): bool
    {
        return self::manage($user, $branchId) || ($user->is_active && $user->workBranchId() === $branchId && in_array($user->id, ClosingSetting::forBranch($branchId)->approvers ?? [], true));
    }
}
