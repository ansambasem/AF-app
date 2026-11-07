<?php

namespace App\Support;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FinancialReportAccess
{
    public static function view(User $user): bool
    {
        return $user->hasAnyPermission(PermissionKey::ViewFinancialReports, PermissionKey::ViewAllFinancialReports);
    }

    public static function viewAllBranches(User $user): bool
    {
        return $user->hasPermission(PermissionKey::ViewAllFinancialReports);
    }

    public static function export(User $user): bool
    {
        return self::view($user) && $user->hasPermission(PermissionKey::ExportFinancialReports);
    }

    /** @return Collection<int, Branch> */
    public static function branches(User $user): Collection
    {
        return Branch::query()
            ->when(! self::viewAllBranches($user), fn (Builder $query): Builder => $query->whereKey($user->workBranchId()))
            ->orderBy('name')
            ->get();
    }
}
