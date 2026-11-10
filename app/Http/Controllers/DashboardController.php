<?php

namespace App\Http\Controllers;

use App\Enums\PermissionKey;
use App\Http\Concerns\ProvidesFormOptions;
use App\Models\Branch;
use App\Models\Governorate;
use App\Models\User;
use App\Support\BranchScope;
use App\Support\DailySeries;
use App\Support\DashboardOverview;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DashboardController extends Controller
{
    use ProvidesFormOptions;

    /**
     * The home page for the branches the user looks after — their own
     * branch, or for the Super Admin the whole company, narrowed by
     * governorate or branch from the picker at the top: the open billing
     * period's accounts, and what waits on someone (readings to approve or
     * enter, open boxes). Each part appears only for
     * someone who may open the page it summarizes.
     */
    public function index(Request $request): InertiaResponse
    {
        $actor = $request->user();
        $validated = $request->validate([
            'branch' => ['nullable', 'integer'],
            'governorate' => ['nullable', 'integer'],
        ]);
        $company = BranchScope::shownTo($actor);
        $allBranches = $company ? Branch::query()->with('governorate')->orderBy('name')->get() : collect();
        $governorateId = $company ? ($validated['governorate'] ?? null) : null;
        $branchId = $company ? ($validated['branch'] ?? null) : null;
        $branches = $company
            ? $allBranches
                ->when($governorateId, fn (Collection $branches) => $branches->where('governorate_id', $governorateId))
                ->when($branchId, fn (Collection $branches) => $branches->where('id', $branchId))
                ->values()
            : Branch::query()->with('governorate')->whereKey($actor->workBranchId())->get();
        $overview = new DashboardOverview($actor, $branches);

        $sections = fn (): array => once(fn (): array => array_filter([
            'readings' => $overview->readings(),
            'boxes' => $overview->boxes(),
        ], fn (?array $section): bool => $section !== null));
        $periodAccounts = fn (): ?array => once(fn (): ?array => $overview->periodAccounts());

        $hour = now(config('app.business_timezone'))->hour;

        return Inertia::render('Dashboard', [
            'greeting' => match (true) {
                $hour < 12 => __('Good morning'),
                $hour < 18 => __('Good afternoon'),
                default => __('Good evening'),
            },
            'scope' => [
                'company' => $company,
                'label' => $company && ! $branchId ? ($governorateId ? $allBranches->firstWhere('governorate_id', $governorateId)?->governorate?->name ?? 'كل الفروع' : 'كل الفروع') : $branches->first()?->name,
                'branches' => $branches->count(),
                'governorates' => $branches->pluck('governorate_id')->filter()->unique()->count(),
                'today' => DailySeries::today()->toDateString(),
            ],
            // The head of the company reads, large at the top, whose figures these are: the company, a governorate or a branch.
            'branchScope' => fn (): ?array => $company
                ? BranchScope::describe($branches, $governorateId && ! $branchId ? Governorate::query()->whereKey($governorateId)->value('name') : null)
                : null,
            'filters' => [
                'branch' => $branchId,
                'governorate' => $governorateId,
            ],
            'branchOptions' => $company ? $allBranches->map(fn (Branch $branch): array => ['value' => $branch->id, 'label' => $branch->name, 'governorate' => $branch->governorate_id])->values() : null,
            'governorateOptions' => $company ? $allBranches->pluck('governorate')->filter()->unique('id')->sortBy('name')->map(fn (Governorate $governorate): array => ['value' => $governorate->id, 'label' => $governorate->name])->values() : null,
            'sections' => $sections,
            // The open billing period's accounts: what the branches are owed and have collected in it.
            'periodAccounts' => $periodAccounts,
            // Someone whose work is in the field app and who has nothing to see here is told so.
            'fieldApp' => fn () => $sections() === [] && $periodAccounts() === null && ($actor->hasPermission(PermissionKey::RecordCollections) || $actor->hasPermission(PermissionKey::RecordMeterReadings))
                ? ['url' => config('powercollect.mobile_app_url')]
                : null,
            'canCreateBranch' => $actor->can('create', Branch::class),
            'canCreateUser' => $actor->can('create', User::class),
            // The "new branch/user" pop-up's dropdowns, loaded only when its button is clicked.
            'branchForm' => Inertia::optional(fn () => $actor->can('create', Branch::class) ? $this->branchFormOptions() : null),
            'userForm' => Inertia::optional(fn () => $actor->can('create', User::class)
                ? $this->userBranchOptions()
                : null),
        ]);
    }
}
