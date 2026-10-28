<?php

namespace App\Http\Concerns;

use App\Enums\MeterReadingStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\MeterReading;
use App\Models\ReadingEntrySetting;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Which subscriptions and readings the weekly reading sheet covers: its
 * branch and week, its columns, search, filters and sort, and the week's
 * pending readings among them, shared by the sheet and its "approve all"
 * so both mean exactly the rows shown. Needs FiltersDataTable.
 */
trait FiltersReadingSheet
{
    /**
     * Pending readings in the actor's branch (every branch for the Super Admin).
     *
     * @return Builder<MeterReading>
     */
    protected function pendingReadings(User $actor): Builder
    {
        return MeterReading::query()
            ->visibleTo($actor)
            ->where('requires_reentry', false)
            ->where('status', MeterReadingStatus::Pending);
    }

    /**
     * The week's pending readings for the subscriptions the sheet shows with
     * the request's search and filters.
     *
     * @return Builder<MeterReading>
     */
    protected function pendingInSheet(Request $request, User $actor, string $week): Builder
    {
        $subscriptions = $this->subscriptionsInScope($actor, $request);
        $this->applyDataTableFilters($subscriptions, $request, ['full_name', 'subscription_name', 'account_number', 'phone', 'subscription_phone'], [], 'full_name');
        $this->applyDataTableFilterSelects($subscriptions, $request, ['branch_id', 'meter_box_id', 'tariff_id']);
        $this->applyMeterBoxNameFilter($subscriptions, $request);
        $this->applySheetFilters($subscriptions, $request, $week);

        return $this->pendingReadings($actor)
            ->whereDate('week_start', $week)
            ->whereIn('subscription_id', $subscriptions->reorder()->select('subscriptions.id'));
    }

    /**
     * Subscriptions matching the selected status, active by default.
     *
     * @return Builder<Subscription>
     */
    protected function subscriptionsInScope(User $actor, Request $request): Builder
    {
        $status = (string) $request->input('filter.subscription_status', SubscriptionStatus::Active->value);

        return Subscription::query()
            ->visibleTo($actor)
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', SubscriptionStatus::tryFrom($status) ?? SubscriptionStatus::Active));
    }

    /**
     * Add each row's reading values for the week as selectable (and so
     * sortable) columns: the meter box number, the last reading the week
     * starts from, and — once entered — the new reading, consumption and
     * amount to pay.
     */
    protected function withSheetColumns(Builder $query, string $week): void
    {
        $thisWeek = fn (string $column) => MeterReading::query()
            ->select($column)
            ->whereColumn('meter_readings.subscription_id', 'subscriptions.id')
            ->whereDate('week_start', $week)
            ->limit(1);

        $lastBefore = MeterReading::query()
            ->selectRaw('COALESCE(next_previous_reading, current_reading)')
            ->whereColumn('meter_readings.subscription_id', 'subscriptions.id')
            ->whereDate('week_start', '<', $week)
            ->orderByDesc('week_start')
            ->limit(1);

        $thisWeekPrevious = $thisWeek('previous_reading');

        $query->select('subscriptions.*')
            ->selectSub(MeterBox::query()->select('box_number')->whereColumn('meter_boxes.id', 'subscriptions.meter_box_id'), 'meter_box_number')
            ->selectSub(MeterBox::query()->select('name')->whereColumn('meter_boxes.id', 'subscriptions.meter_box_id'), 'meter_box_name')
            ->selectSub(MeterBox::query()->select('name_suffix')->whereColumn('meter_boxes.id', 'subscriptions.meter_box_id'), 'meter_box_suffix')
            ->selectRaw(
                "COALESCE(({$thisWeekPrevious->toSql()}), ({$lastBefore->toSql()}), subscriptions.initial_reading, 0) as last_reading",
                [...$thisWeekPrevious->getBindings(), ...$lastBefore->getBindings()],
            )
            ->selectSub($thisWeek('current_reading'), 'current_reading')
            ->selectSub($thisWeek('consumption'), 'consumption')
            ->selectSub($thisWeek('amount_due'), 'amount_due');
    }

    /**
     * `?sort=meter_box`: by meter box — its name, then its suffix, then its
     * number, numbers in numeric order (BOX-9 before BOX-10) — with
     * subscriptions without a box last. Applied before the table's own sort,
     * which then only breaks ties (by name).
     */
    protected function applyMeterBoxSort(Builder $query, Request $request): void
    {
        if ((string) $request->string('sort') !== 'meter_box') {
            return;
        }

        $direction = $request->string('direction')->lower()->value() === 'desc' ? 'desc' : 'asc';

        $query->orderByRaw('meter_box_name IS NULL')
            ->orderBy('meter_box_name', $direction)
            ->orderByRaw('LENGTH(COALESCE(meter_box_suffix, \'\')) '.$direction)
            ->orderBy('meter_box_suffix', $direction)
            ->orderByRaw('LENGTH(meter_box_number) '.$direction)
            ->orderBy('meter_box_number', $direction);
    }

    /**
     * The branch whose reading weeks the sheet follows: the actor's own, or
     * for the Super Admin the branch filtered on, else the first one. A sheet
     * shows the weeks of one schedule, so when the branches do not all read on
     * the same weeks, the Super Admin's sheet is held to that branch — the
     * branch filter is set to it — instead of mixing the weeks.
     */
    protected function sheetBranchId(Request $request, User $actor): ?int
    {
        if (! $actor->isSuperAdmin()) {
            return $actor->workBranchId();
        }

        $chosen = $request->input('filter.branch_id');

        if (filled($chosen)) {
            return (int) $chosen;
        }

        $branchIds = Branch::query()->orderBy('name')->pluck('id');
        $weeksOfBranches = $branchIds->map(function (int $branchId): string {
            $schedule = ReadingEntrySetting::forBranch($branchId);

            return json_encode([$schedule->reading_day, $schedule->reading_day_history]);
        })->unique();

        if ($weeksOfBranches->count() > 1) {
            $request->merge(['filter' => [...(array) $request->input('filter', []), 'branch_id' => (string) $branchIds->first()]]);
        }

        return $branchIds->first();
    }

    /**
     * The requested week (any date is snapped to the first day of its
     * week), defaulting to the latest week that has ended and never later
     * than it — always that week for someone who may not browse earlier ones.
     */
    protected function selectedWeek(Request $request, ?int $branchId, bool $canBrowseWeeks): Carbon
    {
        $latestWeek = MeterReading::latestEndedWeekStart($branchId);
        $requested = $request->date('week');

        if ($requested === null || ! $canBrowseWeeks) {
            return $latestWeek;
        }

        return MeterReading::weekStartFor($requested, $branchId)->min($latestWeek);
    }

    /**
     * How many weeks the pickers offer: back to the week of the first
     * reading the actor may see, but at least 60 and at most 520 (ten years).
     */
    protected function weeksToOffer(User $actor, ?int $branchId): int
    {
        $first = MeterReading::query()->visibleTo($actor)->min('week_start');

        if ($first === null) {
            return 60;
        }

        $weeks = (int) floor(MeterReading::latestEndedWeekStart($branchId)->diffInDays(Carbon::parse($first), absolute: true) / 7) + 1;

        return max(60, min(520, $weeks));
    }

    /**
     * Filters that need a join rather than a plain column match: the
     * area/sub-area a subscription's meter box sits in, whether this week's
     * reading has been entered yet, and whether it has been approved.
     */
    protected function applySheetFilters(Builder $query, Request $request, string $week, bool $includeStatus = true): void
    {
        $filters = (array) $request->input('filter', []);

        if (filled($filters['area_id'] ?? null)) {
            $query->whereHas('branch', fn (Builder $q) => $q->where('area_id', $filters['area_id']));
        }

        if (filled($filters['sub_area_id'] ?? null)) {
            $query->whereHas('meterBox', fn (Builder $q) => $q->where('sub_area_id', $filters['sub_area_id']));
        }

        $this->applyCircuitBreakerFilter($query, $request);

        if ($includeStatus) {
            $this->applySheetStatusFilters($query, $request, $week);
        }
    }

    protected function applySheetStatusFilters(Builder $query, Request $request, string $week): void
    {
        $filters = (array) $request->input('filter', []);

        match ($filters['entry'] ?? null) {
            'entered' => $query->whereHas('meterReadings', fn (Builder $q) => $q->whereDate('week_start', $week)),
            'missing' => $query->whereDoesntHave('meterReadings', fn (Builder $q) => $q->whereDate('week_start', $week)),
            default => null,
        };

        $approval = MeterReadingStatus::tryFrom((string) ($filters['approval'] ?? ''));

        if ($approval !== null) {
            $query->whereHas('meterReadings', fn (Builder $q) => $q->whereDate('week_start', $week)->where('status', $approval));
        }
    }
}
