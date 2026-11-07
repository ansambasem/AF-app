<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\MeterReading;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\DailySeries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * How each branch is doing: what it charged its subscriptions (the lines
 * on their accounts, عليه), its subscriptions, and its staff's entries —
 * subscriptions registered and weekly readings entered — and when. The
 * Super Admin compares every branch; a branch's own staff go straight to
 * their branch. Read-only.
 */
class BranchPerformanceController extends Controller
{
    /**
     * The sort buttons, and the figure each one ranks branches by.
     */
    private const SORTS = [
        'revenue' => 'chargesTotal',
        'subscriptions' => 'activeSubscriptions',
        'activity' => 'weekEntries',
    ];

    private const SPARKLINE_DAYS = 14;

    private const CHART_DAYS = 30;

    private const WORK_LOG_DAYS = 14;

    private const LATEST_REGISTRATIONS = 8;

    public function index(Request $request): InertiaResponse|RedirectResponse
    {
        $this->authorize('viewBranchPerformance', SubscriptionTransaction::class);

        $actor = $request->user();

        if (! $actor->isSuperAdmin()) {
            abort_unless($actor->workBranchId(), 403);

            return redirect()->route('branch-performance.show', $actor->workBranchId());
        }

        $sort = $request->query('sort');
        $sort = is_string($sort) && array_key_exists($sort, self::SORTS) ? $sort : 'revenue';

        $branches = $this->withFigures(Branch::query())->with(['governorate', 'area'])->orderBy('name')->get();
        $entries = $this->entriesPerDay($branches->modelKeys(), self::SPARKLINE_DAYS);

        $summaries = $branches
            ->map(fn (Branch $branch): array => $this->branchSummary($branch, $entries[$branch->id] ?? []))
            ->sortByDesc(self::SORTS[$sort])
            ->values()
            ->map(fn (array $branch, int $index): array => [...$branch, 'rank' => $index + 1]);

        return Inertia::render('BranchPerformance/Index', [
            'sort' => $sort,
            'summary' => [
                'branches' => $branches->count(),
                'activeBranches' => $branches->where('is_active', true)->count(),
                'chargesTotal' => round($summaries->sum('chargesTotal'), 2),
                'subscriptions' => $summaries->sum('subscriptions'),
                'activeSubscriptions' => $summaries->sum('activeSubscriptions'),
                'weekEntries' => $summaries->sum('weekEntries'),
                'todayEntries' => $summaries->sum('todayEntries'),
                'staff' => $summaries->sum('staff'),
            ],
            'branches' => $summaries,
        ]);
    }

    public function show(Request $request, Branch $branch): InertiaResponse
    {
        $this->authorize('viewForBranch', [SubscriptionTransaction::class, $branch]);

        $branch = $this->withFigures(Branch::query())->with(['governorate', 'area'])->findOrFail($branch->id);
        $entries = $this->entriesPerDay([$branch->id], self::CHART_DAYS)[$branch->id] ?? [];
        $charges = fn (): Builder => SubscriptionTransaction::query()->charges()->whereHas('subscription', fn (Builder $query) => $query->where('branch_id', $branch->id));
        $statusCounts = $branch->subscriptions()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('BranchPerformance/Show', [
            'branch' => [
                ...$this->branchSummary($branch, $entries),
                'phone' => $branch->phone,
                'statusCounts' => array_map(fn (array $status): array => [
                    ...$status,
                    'count' => (int) ($statusCounts[$status['value']] ?? 0),
                ], SubscriptionStatus::options()),
                'monthChargesTotal' => round((float) $charges()
                    ->where('subscription_transactions.created_at', '>=', DailySeries::startOfDay(self::CHART_DAYS - 1))
                    ->toBase()
                    ->selectRaw('coalesce(sum(abs(amount)), 0) as total')
                    ->value('total'), 2),
                'monthEntries' => array_sum($entries),
            ],
            'canCompareBranches' => $request->user()->isSuperAdmin(),
            'dailyRegistrations' => DailySeries::counts(Subscription::query()->where('branch_id', $branch->id), 'subscriptions.created_at', self::CHART_DAYS),
            'dailyCharges' => DailySeries::sums($charges(), 'subscription_transactions.created_at', 'subscription_transactions.amount', self::CHART_DAYS),
            'team' => $this->team($branch),
            'workLog' => $this->workLog($branch, $charges()),
            'latestRegistrations' => $branch->subscriptions()
                ->with('registeredBy')
                ->latest()
                ->latest('id')
                ->take(self::LATEST_REGISTRATIONS)
                ->get()
                ->map(fn (Subscription $subscription): array => [
                    'id' => $subscription->id,
                    'name' => $subscription->displayName(),
                    'phone' => $subscription->contactPhone(),
                    'status' => $subscription->status->value,
                    'registeredByName' => $subscription->registeredBy?->name,
                    'createdAt' => $subscription->created_at->toIso8601String(),
                ]),
        ]);
    }

    /**
     * Adds each branch's subscription and staff counts, what it charged its
     * subscriptions, and when its last subscription and reading were entered.
     *
     * @param  Builder<Branch>  $query
     * @return Builder<Branch>
     */
    private function withFigures(Builder $query): Builder
    {
        return $query
            ->withCount([
                'subscriptions',
                'subscriptions as active_subscriptions_count' => fn (Builder $subscriptions) => $subscriptions->where('status', SubscriptionStatus::Active),
                'users as staff_count' => fn (Builder $users) => $users->where('is_active', true),
            ])
            ->withSum(['transactions as charges_total' => fn (Builder $lines) => $lines->charges()], 'amount')
            ->withMax('subscriptions as last_registration_at', 'created_at')
            ->withMax('meterReadings as last_reading_at', 'created_at');
    }

    /**
     * @param  array<string, int>  $entries  entries per business day
     * @return array<string, mixed>
     */
    private function branchSummary(Branch $branch, array $entries): array
    {
        $sparkline = array_map(fn (string $day): int => $entries[$day] ?? 0, DailySeries::lastDays(self::SPARKLINE_DAYS));
        $lastEntryAt = collect([$branch->last_registration_at, $branch->last_reading_at])->filter()->max();

        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'isActive' => $branch->is_active,
            'governorateName' => $branch->governorate?->name,
            'areaName' => $branch->area?->name,
            'chargesTotal' => round((float) $branch->charges_total, 2),
            'subscriptions' => $branch->subscriptions_count,
            'activeSubscriptions' => $branch->active_subscriptions_count,
            'staff' => $branch->staff_count,
            'todayEntries' => $entries[DailySeries::today()->toDateString()] ?? 0,
            'weekEntries' => array_sum(array_slice($sparkline, -7)),
            'sparkline' => $sparkline,
            'lastEntryAt' => $lastEntryAt ? CarbonImmutable::parse($lastEntryAt, 'UTC')->toIso8601String() : null,
        ];
    }

    /**
     * How many entries — subscriptions registered and readings entered — each
     * branch made on each of the last `$days` business days.
     *
     * @param  array<int, int>  $branchIds
     * @return array<int, array<string, int>> branch id => [Y-m-d => entries]
     */
    private function entriesPerDay(array $branchIds, int $days): array
    {
        return $this->entries($branchIds, DailySeries::startOfDay($days - 1))
            ->groupBy('branch_id')
            ->map(fn (Collection $entries): array => $entries->countBy(fn (object $entry): string => DailySeries::localDate($entry->created_at))->all())
            ->all();
    }

    /**
     * The branch's staff, busiest first: how many entries each made here
     * (all time and in the last 7 days), the account lines they recorded
     * here — how many, and their total on each side (عليه and له) — and
     * when they last did any of it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function team(Branch $branch): array
    {
        $members = $branch->users()->with('userType')->orderBy('name')->get();
        $weekStart = DailySeries::startOfDay(6);
        $perMember = fn (Builder $query, string $member): Collection => $query
            ->whereIn($member, $members->modelKeys())
            ->toBase()
            ->selectRaw("{$member} as user_id, count(*) as entries, sum(case when created_at >= ? then 1 else 0 end) as week_entries, max(created_at) as last_at", [$weekStart])
            ->groupBy($member)
            ->get()
            ->keyBy('user_id');

        $registrations = $perMember(Subscription::query()->where('branch_id', $branch->id), 'registered_by');
        $readings = $perMember(MeterReading::query()->where('branch_id', $branch->id), 'recorded_by');
        $credit = implode(', ', array_fill(0, count(SubscriptionTransaction::CREDIT_TYPES), '?'));
        $recorded = SubscriptionTransaction::query()
            ->counted()
            ->whereIn('recorded_by', $members->modelKeys())
            ->whereHas('subscription', fn (Builder $query) => $query->where('branch_id', $branch->id))
            ->toBase()
            ->selectRaw(
                "recorded_by as user_id, count(*) as entries, max(created_at) as last_at,
                    sum(case when type in ({$credit}) or (type = ? and amount < 0) then 0 else abs(amount) end) as charged,
                    sum(case when type in ({$credit}) or (type = ? and amount < 0) then abs(amount) else 0 end) as credited",
                [...SubscriptionTransaction::CREDIT_TYPES, SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT, ...SubscriptionTransaction::CREDIT_TYPES, SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT],
            )
            ->groupBy('recorded_by')
            ->get()
            ->keyBy('user_id');

        return $members
            ->map(function (User $member) use ($registrations, $readings, $recorded): array {
                $registered = $registrations[$member->id] ?? null;
                $read = $readings[$member->id] ?? null;
                $lines = $recorded[$member->id] ?? null;
                $lastActivityAt = collect([$registered?->last_at, $read?->last_at, $lines?->last_at])->filter()->max();

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'username' => $member->username,
                    'roleLabel' => $member->isSuperAdmin() ? __($member->role->label()) : ($member->userType?->name ?? 'موظف'),
                    'isActive' => $member->is_active,
                    'entries' => (int) ($registered?->entries ?? 0) + (int) ($read?->entries ?? 0),
                    'weekEntries' => (int) ($registered?->week_entries ?? 0) + (int) ($read?->week_entries ?? 0),
                    'recordedCount' => (int) ($lines?->entries ?? 0),
                    'recordedCharged' => round((float) ($lines?->charged ?? 0), 2),
                    'recordedCredited' => round((float) ($lines?->credited ?? 0), 2),
                    'lastActivityAt' => $lastActivityAt ? CarbonImmutable::parse($lastActivityAt, 'UTC')->toIso8601String() : null,
                ];
            })
            ->sortByDesc('entries')
            ->values()
            ->all();
    }

    /**
     * The last two weeks, newest first: the day's new subscriptions and
     * entries, what was charged, and who made the most entries.
     *
     * @param  Builder<SubscriptionTransaction>  $charges
     * @return array<int, array{date: string, newSubscriptions: int, entries: int, chargesCount: int, chargesTotal: float, topEntrant: string|null}>
     */
    private function workLog(Branch $branch, Builder $charges): array
    {
        $since = DailySeries::startOfDay(self::WORK_LOG_DAYS - 1);
        $byDay = fn (Collection $rows): Collection => $rows->groupBy(fn (object $row): string => DailySeries::localDate($row->created_at));

        $entries = $byDay($this->entries([$branch->id], $since));
        $charged = $byDay($charges->where('subscription_transactions.created_at', '>=', $since)->toBase()->get(['subscription_transactions.created_at', 'amount']));
        $names = User::query()->whereIn('id', $entries->flatten()->pluck('user_id')->unique())->pluck('name', 'id');

        return array_map(function (string $day) use ($entries, $charged, $names): array {
            $dayEntries = $entries[$day] ?? collect();
            $topEntrant = $dayEntries->countBy('user_id')->sortDesc()->keys()->first();

            return [
                'date' => $day,
                'newSubscriptions' => $dayEntries->where('kind', 'registration')->count(),
                'entries' => $dayEntries->count(),
                'chargesCount' => ($charged[$day] ?? collect())->count(),
                'chargesTotal' => round(($charged[$day] ?? collect())->sum(fn (object $line): float => abs((float) $line->amount)), 2),
                'topEntrant' => $topEntrant ? ($names[$topEntrant] ?? null) : null,
            ];
        }, array_reverse(DailySeries::lastDays(self::WORK_LOG_DAYS)));
    }

    /**
     * Every entry made in the given branches since `$since`: subscriptions
     * registered and readings entered, each with its branch, who made it
     * and when.
     *
     * @param  array<int, int>  $branchIds
     * @return Collection<int, object{branch_id: int, user_id: int, created_at: string, kind: string}>
     */
    private function entries(array $branchIds, CarbonImmutable $since): Collection
    {
        $registrations = Subscription::query()
            ->whereIn('branch_id', $branchIds)
            ->where('created_at', '>=', $since)
            ->toBase()
            ->selectRaw("branch_id, registered_by as user_id, created_at, 'registration' as kind")
            ->get();

        $readings = MeterReading::query()
            ->whereIn('branch_id', $branchIds)
            ->where('created_at', '>=', $since)
            ->toBase()
            ->selectRaw("branch_id, recorded_by as user_id, created_at, 'reading' as kind")
            ->get();

        return $registrations->concat($readings);
    }
}
