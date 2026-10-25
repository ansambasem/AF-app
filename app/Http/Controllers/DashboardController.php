<?php

namespace App\Http\Controllers;

use App\Enums\PermissionKey;
use App\Http\Concerns\BuildsSubscriptionStatement;
use App\Http\Concerns\ProvidesFormOptions;
use App\Http\Concerns\ProvidesSubscriptionForm;
use App\Models\Branch;
use App\Models\Governorate;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BranchDomainContext;
use App\Support\DailySeries;
use App\Support\DashboardOverview;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DashboardController extends Controller
{
    use BuildsSubscriptionStatement, ProvidesFormOptions, ProvidesSubscriptionForm;

    /**
     * The home page: the figures of the branches the user looks after —
     * their own branch, or for the Super Admin the whole company, narrowed
     * by governorate or branch — over the last 7, 30 or 90 days. Each part
     * appears only for someone who may open the page it summarizes, so the
     * dashboard differs per user without any role name hardcoded here.
     */
    public function index(Request $request): InertiaResponse
    {
        $actor = $request->user();
        $validated = $request->validate([
            'days' => ['nullable', 'integer', Rule::in(DashboardOverview::PERIODS)],
            'branch' => ['nullable', 'integer'],
            'governorate' => ['nullable', 'integer'],
            'method' => ['nullable', Rule::in(['cash', 'transfer'])],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $days = (int) ($validated['days'] ?? 30);
        $company = $actor->isSuperAdmin() && BranchDomainContext::id() === null;
        $allBranches = $company ? Branch::query()->with('governorate')->orderBy('name')->get() : collect();
        $governorateId = $company ? ($validated['governorate'] ?? null) : null;
        $branchId = $company ? ($validated['branch'] ?? null) : null;
        $branches = $company
            ? $allBranches
                ->when($governorateId, fn (Collection $branches) => $branches->where('governorate_id', $governorateId))
                ->when($branchId, fn (Collection $branches) => $branches->where('id', $branchId))
                ->values()
            : Branch::query()->with('governorate')->whereKey($actor->workBranchId())->get();
        $overview = new DashboardOverview($actor, $branches, $days);

        $sections = fn (): array => once(fn (): array => array_filter([
            'collections' => $overview->collections(),
            'subscriptions' => $overview->subscriptions(),
            'meterBoxes' => $overview->meterBoxes(),
            'readings' => $overview->readings(),
            'disconnections' => $overview->disconnections(),
            'boxes' => $overview->boxes(),
        ], fn (?array $section): bool => $section !== null));

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
            'filters' => [
                'days' => $days,
                'branch' => $branchId,
                'governorate' => $governorateId,
                'method' => $validated['method'] ?? null,
                'search' => $validated['search'] ?? '',
            ],
            'periods' => DashboardOverview::PERIODS,
            'branchOptions' => $company ? $allBranches->map(fn (Branch $branch): array => ['value' => $branch->id, 'label' => $branch->name, 'governorate' => $branch->governorate_id])->values() : null,
            'governorateOptions' => $company ? $allBranches->pluck('governorate')->filter()->unique('id')->sortBy('name')->map(fn (Governorate $governorate): array => ['value' => $governorate->id, 'label' => $governorate->name])->values() : null,
            'sections' => $sections,
            // Aged one debtor at a time, so it follows the page instead of holding it up.
            'debts' => Inertia::defer(fn () => $overview->debts()),
            'latestPayments' => fn () => $overview->latestPayments($validated['method'] ?? null, trim((string) ($validated['search'] ?? '')), (int) ($validated['page'] ?? 1)),
            // Someone whose work is in the field app and who has nothing to see here is told so.
            'fieldApp' => fn () => $sections() === [] && ! $actor->can('viewDebtAging', SubscriptionTransaction::class) && ($actor->hasPermission(PermissionKey::RecordCollections) || $actor->hasPermission(PermissionKey::RecordMeterReadings))
                ? ['url' => config('powercollect.mobile_app_url')]
                : null,
            'canCreateSubscription' => $actor->can('create', Subscription::class),
            'canCreateBranch' => $actor->can('create', Branch::class),
            'canCreateUser' => $actor->can('create', User::class),
            // The "new branch/user" pop-up's dropdowns, loaded only when its button is clicked.
            'branchForm' => Inertia::optional(fn () => $actor->can('create', Branch::class) ? $this->branchFormOptions() : null),
            'userForm' => Inertia::optional(fn () => $actor->can('create', User::class)
                ? $this->userBranchOptions()
                : null),
            // A subscriber's statement — their account, with the payment, charge and discount forms — opened over the dashboard.
            'statement' => fn () => $this->requestedStatement($request, $actor),
            // The "new subscriber" and "edit subscriber" windows: their dropdowns, and the subscriber being edited, loaded when opened.
            'subscriptionForm' => Inertia::optional(fn () => $actor->can('create', Subscription::class) || $actor->isSuperAdmin() || $actor->hasPermission(PermissionKey::UpdateSubscriptions)
                ? array_map(fn (mixed $option): mixed => $option instanceof Closure ? $option() : $option, $this->subscriptionFormOptions())
                : null),
            'subscriptionEdit' => Inertia::optional(fn () => $this->requestedSubscriptionEdit($request, $actor)),
        ]);
    }

    /**
     * The subscriber named by `?edit=`, in the shape the edit window starts
     * from, when the actor may edit them; null otherwise.
     *
     * @return array<string, mixed>|null
     */
    private function requestedSubscriptionEdit(Request $request, User $actor): ?array
    {
        $subscriptionId = $request->query('edit');

        if (! is_string($subscriptionId) || ! ctype_digit($subscriptionId)) {
            return null;
        }

        $subscription = Subscription::query()->readableTo($actor)->with(['profile', 'meterBox'])->find($subscriptionId);

        if (! $subscription || ! $actor->can('update', $subscription)) {
            return null;
        }

        return [
            ...$this->subscriptionEditableFields($subscription),
            'subscriptionCount' => $subscription->profile?->subscriptions()->readableTo($actor)->count() ?? 1,
        ];
    }
}
