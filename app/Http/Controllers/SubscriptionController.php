<?php

namespace App\Http\Controllers;

use App\Enums\PermissionKey;
use App\Enums\SubscriptionStatus;
use App\Http\Concerns\BuildsSubscriptionStatement;
use App\Http\Concerns\DeletesRecords;
use App\Http\Concerns\FiltersDataTable;
use App\Http\Concerns\FiltersSubscriptionList;
use App\Http\Concerns\ProvidesSubscriptionForm;
use App\Http\Concerns\ReturnsToDashboard;
use App\Http\Requests\StoreSubscriptionRequest;
use App\Http\Requests\UpdateSubscriptionRequest;
use App\Models\Branch;
use App\Models\CircuitBreaker;
use App\Models\MessageBatch;
use App\Models\MeterBox;
use App\Models\MeterReading;
use App\Models\SubArea;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\Tariff;
use App\Models\TariffSegment;
use App\Models\User;
use App\Notifications\ActionCompleted;
use App\Support\BranchScope;
use App\Support\DailySeries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SubscriptionController extends Controller
{
    use BuildsSubscriptionStatement, DeletesRecords, FiltersDataTable, FiltersSubscriptionList, ProvidesSubscriptionForm, ReturnsToDashboard;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorize('viewAny', Subscription::class);

        $actor = auth()->user();
        $showArchived = $request->boolean('filter.archived');

        if ($showArchived) {
            $this->authorize('viewArchived', Subscription::class);
        }

        $query = Subscription::query()
            ->select('subscriptions.*')
            ->selectRaw("COALESCE(NULLIF(subscription_name, ''), full_name) as display_name")
            ->readableTo($actor)
            ->with(['branch.area', 'branch.governorate', 'meterBox.subArea', 'tariff', 'tariffSegment', 'circuitBreaker', 'standingDiscount', 'registeredBy'])
            ->with(['meterReadings' => fn ($readings) => $readings->chaperone()->withLaterReadingsCount()->with('recordedBy')])
            ->with(['profile' => fn ($query) => $query->withCount(['subscriptions' => fn ($subscriptions) => $subscriptions->readableTo($actor)])])
            ->withSum('transactions as outstanding_balance', 'amount')
            ->withExists(['transactions as has_subscription_fee' => fn (Builder $transactions) => $transactions->where('type', SubscriptionTransaction::TYPE_SUBSCRIPTION_FEE)]);
        $showArchived ? $query->whereNotNull('subscriptions.archived_at') : $query->whereNull('subscriptions.archived_at');
        $this->applySubscriptionListFilters($query, $request, includeArchived: true);

        $canRecordReadings = $actor->can('create', MeterReading::class);

        $subscriptions = fn () => $query->paginate($this->dataTablePerPage($request))
            ->withQueryString()
            ->through(fn (Subscription $subscription) => $this->indexRow($subscription, $actor, $canRecordReadings));

        return Inertia::render('Subscriptions/Index', [
            'subscriptions' => $subscriptions,
            'canCreate' => $actor->can('create', Subscription::class),
            'canViewArchived' => $actor->can('viewArchived', Subscription::class),
            'showArchived' => $showArchived,
            'archiveToday' => DailySeries::today()->toDateString(),
            'canRecordReadings' => $canRecordReadings,
            'bulkActions' => [
                'minimumCharge' => $actor->can('bulkUpdate', [Subscription::class, 'minimum_charge']),
                'status' => $actor->can('bulkUpdate', [Subscription::class, 'status']),
                'meterBox' => $actor->can('bulkUpdate', [Subscription::class, 'meter_box_id']),
                'message' => $actor->can('create', MessageBatch::class),
            ],
            'statusOptions' => SubscriptionStatus::options(),
            'filters' => $this->dataTableState($request, 'display_name'),
            'filterOptions' => fn () => $this->filterOptions($actor, $request),
            // Whoever sees every branch's subscribers reads, large above the table, which branch the list holds.
            'branchScope' => fn () => BranchScope::shownTo($actor) ? BranchScope::forFilter($actor, $request->input('filter.branch_id')) : null,
            // New reading forms offer only the latest week of each branch.
            'readingWeekOptions' => fn () => MeterReading::recentWeekOptionsFor($actor, 1),
            'statement' => fn () => $this->requestedStatement($request, $actor),
            ...$this->subscriptionFormOptions(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): InertiaResponse
    {
        $this->authorize('create', Subscription::class);

        return Inertia::render('Subscriptions/Create', $this->subscriptionFormOptions());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSubscriptionRequest $request): RedirectResponse
    {
        $actor = auth()->user();
        $data = $request->safe()->except(['charge_subscription_fee', 'source_subscription_id']);
        $chargeSubscriptionFee = $request->boolean('charge_subscription_fee');
        $sourceSubscription = $request->sourceSubscription();

        if (! $actor->isSuperAdmin()) {
            $data['branch_id'] = $actor->workBranchId();
        }

        $data['registered_by'] = $actor->id;
        // A subscription connected when registered starts today, unless a date was picked.
        if ($data['status'] === SubscriptionStatus::Active->value && blank($data['subscription_date'] ?? null)) {
            $data['subscription_date'] = DailySeries::today()->toDateString();
        }
        $data = $this->enforceMinimumChargePermission($actor, $data);
        $data = $this->enforceKilowattPricePermission($actor, $data);

        $subscription = DB::transaction(function () use ($data, $actor, $chargeSubscriptionFee, $sourceSubscription): Subscription {
            $subscription = new Subscription($data);

            if ($sourceSubscription !== null) {
                $profile = $sourceSubscription->profile()->lockForUpdate()->firstOrFail();
                $subscription->profile()->associate($profile);
            }

            $subscription->save();

            if ($chargeSubscriptionFee) {
                $this->chargeSubscriptionFee($subscription, $actor);
            }

            return $subscription;
        });

        $actor->notify(new ActionCompleted('subscription-created', $subscription->displayName()));

        return $this->redirectAfterSave($request, 'subscriptions.index', 'subscription-created');
    }

    /**
     * Charge the subscription's subscription fee to their account, as a
     * subscription-fee line, if it has an amount.
     */
    private function chargeSubscriptionFee(Subscription $subscription, User $actor): void
    {
        if ($subscription->subscription_fee === null || (float) $subscription->subscription_fee <= 0) {
            return;
        }

        $subscription->transactions()->create([
            'recorded_by' => $actor->id,
            'type' => SubscriptionTransaction::TYPE_SUBSCRIPTION_FEE,
            'source_key' => 'subscription-fee:'.$subscription->id,
            'amount' => $subscription->subscription_fee,
            'currency_amount' => $subscription->subscription_fee,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Subscription $subscription): InertiaResponse
    {
        $this->authorize('update', $subscription);

        return Inertia::render('Subscriptions/Edit', [
            'subscription' => [
                ...$this->subscriptionEditableFields($subscription),
                'subscriptionCount' => $subscription->profile->subscriptions()->readableTo(auth()->user())->count(),
            ],
            ...$this->subscriptionFormOptions(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSubscriptionRequest $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->safe()->except(['charge_subscription_fee']);
        $data = $this->enforceMinimumChargePermission(auth()->user(), $data, $subscription);
        $data = $this->enforceKilowattPricePermission(auth()->user(), $data, $subscription);
        // Only a subscription without the fee on the account is validated for charging it.
        $chargeSubscriptionFee = (bool) $request->validated('charge_subscription_fee', false);

        // Activating a subscription who was not active starts their subscription today, unless a date was picked;
        // one who had been active before is connected again, keeping the day they first subscribed.
        if ($data['status'] === SubscriptionStatus::Active->value && $subscription->status !== SubscriptionStatus::Active) {
            $dates = $subscription->datesWhenActivated();
            $datePicked = ($data['subscription_date'] ?? null) !== $subscription->subscription_date?->format('Y-m-d');
            $data = [...$data, ...($datePicked ? Arr::except($dates, 'subscription_date') : $dates)];
        }

        DB::transaction(function () use ($subscription, $data, $chargeSubscriptionFee, $request): void {
            Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $subscription->refresh();
            $this->authorize('update', $subscription);
            $subscription->profile()->lockForUpdate()->firstOrFail();
            $subscription->update($data);

            if ($chargeSubscriptionFee) {
                $this->chargeSubscriptionFee($subscription, $request->user());
            }
        });
        $request->user()->notify(new ActionCompleted('subscription-updated', $subscription->displayName()));

        return $this->redirectAfterSave($request, 'subscriptions.index', 'subscription-updated');
    }

    /**
     * Delete the subscription, once nothing uses it any more.
     */
    public function destroy(Request $request, Subscription $subscription): RedirectResponse
    {
        return $this->deleteRecord($request, $subscription, 'subscription-deleted', $subscription->displayName(), fn () => $subscription->deleteWithSubscriptionFee());
    }

    /**
     * One row of the subscriptions list: the editable fields, plus what the
     * table, the details window and the reading form display.
     *
     * @return array<string, mixed>
     */
    private function indexRow(Subscription $subscription, User $actor, bool $canRecordReadings): array
    {
        $readings = $subscription->meterReadings->sortByDesc('week_start')->values();
        $latestReading = $readings->first();
        $entryWeek = MeterReading::latestEndedWeekStart($subscription->branch_id);
        $entryReading = $readings->first(fn (MeterReading $reading): bool => $reading->week_start->equalTo($entryWeek));
        $hasPreviousReading = $readings->contains(fn (MeterReading $reading): bool => $reading->week_start->lessThan($entryWeek));
        $hasLaterReading = $readings->contains(fn (MeterReading $reading): bool => $reading->week_start->greaterThan($entryWeek));

        return [
            ...$this->subscriptionEditableFields($subscription),
            'branchName' => $subscription->branch->name,
            'governorateName' => $subscription->branch->governorate?->name,
            'areaName' => $subscription->branch->area?->name,
            'meterBoxNumber' => $subscription->meterBox?->box_number,
            'meterBoxName' => $subscription->meterBox?->displayName(),
            'subAreaId' => $subscription->meterBox?->sub_area_id,
            'subAreaName' => $subscription->meterBox?->subArea?->name,
            'tariffCategory' => $subscription->tariff->category->value,
            'tariffCategoryLabel' => __($subscription->tariff->category->label()),
            'tariffSegmentName' => $subscription->tariffSegment?->name,
            'tariffRate' => $subscription->tariff->rate,
            'kilowattPrice' => $subscription->kilowattPrice(),
            'hasOwnKilowattPrice' => $subscription->hasOwnKilowattPrice(),
            'circuitBreakerAmpere' => $subscription->circuitBreaker?->ampere,
            'standingDiscountSummary' => $subscription->standingDiscount?->summary(),
            'standingDiscount' => $subscription->standingDiscount ? ['method' => $subscription->standingDiscount->method->value, 'value' => $subscription->standingDiscount->value] : null,
            'statusLabel' => $subscription->archived_at !== null ? 'مؤرشف' : __($subscription->status->label()),
            'archivedAt' => $subscription->archived_at?->toIso8601String(),
            'serviceEndedOn' => $subscription->service_ended_on?->format('Y-m-d'),
            'archiveReason' => $subscription->archive_reason,
            'archiveHistory' => $subscription->archive_history ?? [],
            'canArchive' => $actor->can('archive', $subscription),
            'archiveBlocker' => $subscription->archiveBlocker(),
            'canRestore' => $subscription->archived_at !== null && $actor->can('restore', $subscription),
            'registeredByName' => $subscription->registeredBy?->name,
            'outstandingBalance' => $subscription->outstanding_balance ?? '0.00',
            'weeklyMinimumPayment' => $subscription->weeklyMinimumPayment(),
            'subscriptionCount' => $subscription->profile?->subscriptions_count ?? 1,
            'meterReadings' => $readings->map(fn (MeterReading $reading) => $this->statementReading($reading, $actor)),
            'lastReading' => $latestReading?->requires_reentry ? $latestReading->previous_reading : ($latestReading?->nextPreviousReading() ?? (float) ($subscription->initial_reading ?? 0)),
            'lastReadingWeekStart' => $latestReading?->week_start->format('Y-m-d'),
            'editableLastReadingId' => $this->editableLastReadingId($subscription, $readings, $actor),
            'initialReading' => ! $hasPreviousReading ? [
                'isInitial' => true, 'weekStart' => $entryWeek->toDateString(),
                'value' => $entryReading?->previous_reading ?? (float) ($subscription->initial_reading ?? 0),
                'previewUrl' => route('subscriptions.reading-baseline.preview', $subscription),
                'saveUrl' => route('subscriptions.reading-baseline.update', $subscription),
                'canEdit' => ! $hasLaterReading && $actor->can('adjustInitialReading', [$subscription, $entryWeek]),
            ] : null,
            'canRecordReading' => $canRecordReadings && ! $hasLaterReading && $subscription->status === SubscriptionStatus::Active,
            'canUpdate' => $actor->can('update', $subscription),
            'canDelete' => $actor->can('delete', $subscription),
            'canRecordPayment' => $actor->can('recordPayment', $subscription),
            'canRecordSplitPayment' => $actor->can('recordSplitPayment', Subscription::class) && $actor->can('recordPayment', $subscription),
            'canAddCharge' => $actor->can('addCharge', $subscription),
            'canAddDiscount' => $actor->can('addDiscount', $subscription),
            'canAddClearing' => $actor->can('addClearing', $subscription),
        ];
    }

    /**
     * The reading the subscription's latest week starts from — its last
     * reading — when the actor may edit it in the reading history: no week
     * after it but the latest one has been entered.
     *
     * @param  Collection<int, MeterReading>  $readings  newest first
     */
    private function editableLastReadingId(Subscription $subscription, Collection $readings, User $actor): ?int
    {
        $latestWeekStart = MeterReading::latestEndedWeekStart($subscription->branch_id);
        $lastReading = $readings->first(fn (MeterReading $reading): bool => $reading->week_start->lessThan($latestWeekStart));

        if ($lastReading === null || $readings->filter(fn (MeterReading $reading): bool => $reading->week_start->greaterThan($lastReading->week_start))->count() > 1) {
            return null;
        }

        return $actor->can('adjustLastReading', $lastReading) ? $lastReading->id : null;
    }

    /**
     * One weekly reading as listed in a subscription's statement.
     *
     * @return array<string, mixed>
     */
    private function statementReading(MeterReading $reading, User $actor): array
    {
        return [
            'id' => $reading->id,
            'weekStart' => $reading->week_start->format('Y-m-d'),
            'weekEnd' => $reading->week_end->format('Y-m-d'),
            'previous_reading' => $reading->previous_reading,
            'current_reading' => $reading->requires_reentry ? null : $reading->current_reading,
            'nextPreviousReading' => $reading->nextPreviousReading(),
            'requiresReentry' => $reading->requires_reentry,
            'adjustmentHistory' => $reading->adjustment_history ?? [],
            'consumption' => $reading->consumption,
            'amountDue' => $reading->amount_due,
            'discountAmount' => $reading->discount_amount,
            'discount' => $reading->discount_method ? ['method' => $reading->discount_method->value, 'value' => $reading->discount_value] : null,
            'unitPrice' => $reading->unit_price,
            'minimumPayment' => $reading->minimum_payment,
            'status' => $reading->status->value,
            'statusLabel' => __($reading->status->label()),
            'notes' => $reading->notes,
            'recordedByName' => $reading->recordedBy?->name,
            'recordedAt' => $reading->created_at->copy()->setTimezone(config('app.business_timezone'))->format('Y-m-d H:i'),
            'recordedSource' => $reading->mobile_operation_id !== null ? 'app' : 'web',
            'minimumApplied' => $reading->discount_method === null && (float) $reading->reading_fee < (float) $reading->minimum_payment,
            'canUpdate' => $actor->can('update', $reading),
            'canApprove' => $actor->can('approve', $reading),
            'unusual' => $reading->isUnusual() ? ['usual' => $reading->usual_consumption] : null,
            'canUpdateUnitPrice' => $actor->can('updateUnitPrice', $reading),
            'canUpdateMinimumPayment' => $actor->can('updateMinimumPayment', $reading),
        ];
    }

    /**
     * Without the dedicated permission, minimum_charge is never taken from
     * the request as-is — it always tracks the chosen circuit breaker's own
     * minimum_payment (or, on update with no circuit breaker chosen, stays
     * whatever the subscription already had). This mirrors the frontend's
     * locked field, but enforced server-side so it can't be bypassed by
     * crafting a raw request.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function enforceMinimumChargePermission(User $actor, array $data, ?Subscription $existing = null): array
    {
        if ($this->canEditMinimumCharge($actor)) {
            return $data;
        }

        $circuitBreaker = ! empty($data['circuit_breaker_id']) ? CircuitBreaker::find($data['circuit_breaker_id']) : null;

        $data['minimum_charge'] = match (true) {
            $circuitBreaker !== null => $circuitBreaker->minimum_payment,
            $existing !== null => $existing->minimum_charge,
            default => 0,
        };

        return $data;
    }

    /**
     * The kilo price a subscriber is saved with. Only someone holding the
     * dedicated permission can give one: it is stored when it differs from
     * the tariff's, and left empty — so they follow the tariff — when it is
     * blank or equal to it. Anyone else never sets it from the request: a new
     * subscriber follows their tariff, and an existing one keeps the price
     * they have, unless their tariff was changed, which a price chosen for
     * the old one no longer fits.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function enforceKilowattPricePermission(User $actor, array $data, ?Subscription $existing = null): array
    {
        if ($actor->hasPermission(PermissionKey::UpdateSubscriptionKilowattPrice) && array_key_exists('kilowatt_price', $data)) {
            $tariffRate = (float) Tariff::findOrFail($data['tariff_id'])->rate;

            $data['kilowatt_price'] = filled($data['kilowatt_price']) && (float) $data['kilowatt_price'] !== $tariffRate
                ? $data['kilowatt_price']
                : null;

            return $data;
        }

        $data['kilowatt_price'] = $existing !== null && (int) $existing->tariff_id === (int) $data['tariff_id']
            ? $existing->kilowatt_price
            : null;

        return $data;
    }

    /**
     * The "Minimum charge" dropdown: each minimum charge (الحد الأدنى) the
     * visible subscriptions have, smallest first.
     *
     * @return array{key: string, label: string, options: array<int, array{value: string, label: string}>}
     */
    private function minimumChargeFilterGroup(User $actor): array
    {
        return $this->filterGroup('minimum_charge', 'الحد الأدنى', Subscription::query()
            ->readableTo($actor)
            ->whereNotNull('minimum_charge')
            ->distinct()
            ->orderBy('minimum_charge')
            ->pluck('minimum_charge')
            ->map(fn ($charge) => ['value' => (string) (float) $charge, 'label' => SubscriptionTransaction::formatAmount($charge).' شيكل']));
    }

    /**
     * The Filter menu's dropdown groups for the index page. The branch
     * filter only makes sense for a Super Admin — everyone else's list is
     * already scoped to their own single branch.
     *
     * @return array<int, array{key: string, label: string, options: array<int, array{value: string, label: string}>}>
     */
    private function filterOptions(User $actor, Request $request): array
    {
        $groups = [
            $this->filterGroup('status', 'الحالة', SubscriptionStatus::options()),
            $this->filterGroup('tariff_id', 'نوع الاشتراك', $this->modelOptions(
                Tariff::orderBy('category')->get(),
                fn (Tariff $tariff) => __($tariff->category->label()),
            )),
            $this->filterGroup('tariff_segment_id', 'تصنيف الزبائن', $this->modelOptions(TariffSegment::orderBy('name')->get(), 'name')),
            $this->subAreaFilterGroup(SubArea::query()->visibleTo($actor)->orderBy('name')->get()),
            ...$this->meterBoxFilterGroupsFor(
                $actor,
                $request,
                $actor->canViewAllSubscriberAccounts() ? fn (MeterBox $box) => $box->branch->name : null,
            ),
            $this->circuitBreakerFilterGroup(),
            $this->minimumChargeFilterGroup($actor),
        ];

        if ($actor->canViewAllSubscriberAccounts()) {
            $groups[] = $this->branchFilterGroup($actor);
        }

        return $groups;
    }
}
