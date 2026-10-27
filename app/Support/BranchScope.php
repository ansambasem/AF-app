<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The branches a page's figures belong to, as the branch banner at the top
 * of the page names them: one branch with its place, phone and subscribers,
 * a governorate's branches (when the page was narrowed to one), or every
 * branch the user may see, counted.
 */
class BranchScope
{
    /**
     * Whether `$actor` sees the banner: the company's general management
     * (a Super Admin on the company's own address). Everyone else, a branch
     * manager or an auditor of some branches included, keeps the page as it
     * was, with the branch named or picked in its usual place.
     */
    public static function shownTo(User $actor): bool
    {
        return $actor->isSuperAdmin() && BranchDomainContext::id() === null;
    }

    /**
     * The banner of a list page whose table narrows to one branch with its
     * `filter[branch_id]`: that branch, or every branch the user may see
     * (the same ones the filter offers) when none, or one out of reach, is
     * chosen. `allName` names what the page shows with no branch chosen,
     * for the picker's empty choice.
     *
     * @return array{level: string, all: bool, name: string, allName: string, place: ?string, phone: ?string, isActive: bool, branches: int, subscribers: int}
     */
    public static function forFilter(User $actor, mixed $branchId): array
    {
        $visible = Branch::query()->orderBy('name')->tap(fn (Builder $branches) => $actor->scopeAuditBranches($branches, 'branches.id'))->get(['id', 'name']);
        $chosen = is_scalar($branchId) && (string) $branchId !== '' ? $visible->where('id', (int) $branchId)->values() : collect();
        $allName = $visible->count() === 1 ? $visible->first()->name : 'جميع الفروع';

        $scope = self::describe($chosen->isNotEmpty() ? $chosen : $visible);

        return [...$scope, 'name' => $scope['level'] === 'all' ? $allName : $scope['name'], 'allName' => $allName];
    }

    /**
     * @param  Collection<int, Branch>  $chosen
     * @param  string|null  $governorate  the governorate's name, when the page was narrowed to one rather than to a branch
     * @return array{level: string, all: bool, name: string, place: ?string, phone: ?string, isActive: bool, branches: int, subscribers: int}
     */
    public static function describe(Collection $chosen, ?string $governorate = null): array
    {
        $branches = Branch::query()
            ->whereKey($chosen->pluck('id'))
            ->with(['governorate:id,name', 'area:id,name'])
            ->withCount('subscriptions')
            ->get();
        $level = $governorate !== null ? 'governorate' : ($branches->count() > 1 ? 'all' : 'branch');
        $branch = $level === 'branch' ? $branches->first() : null;

        return [
            'level' => $level,
            'all' => $level !== 'branch',
            'name' => match ($level) {
                'governorate' => $governorate,
                'all' => 'جميع الفروع',
                default => $branch?->name ?? '',
            },
            'place' => $branch ? (collect([$branch->governorate?->name, $branch->area?->name])->filter()->implode(' · ') ?: $branch->location) : null,
            'phone' => $branch?->phone,
            'isActive' => $branch === null || (bool) $branch->is_active,
            'branches' => $branches->count(),
            'subscribers' => (int) $branches->sum('subscriptions_count'),
        ];
    }
}
