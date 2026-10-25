<?php

namespace App\Http\Controllers;

use App\Enums\MeterReadingStatus;
use App\Enums\PermissionKey;
use App\Enums\ReadingEntryMode;
use App\Enums\SubscriptionStatus;
use App\Http\Concerns\FiltersDataTable;
use App\Http\Requests\AdjustLastReadingRequest;
use App\Http\Requests\ApproveMeterReadingsRequest;
use App\Http\Requests\PreviewLastReadingRequest;
use App\Http\Requests\StoreMeterReadingRequest;
use App\Http\Requests\UpdateMeterReadingRequest;
use App\Http\Requests\UpdateReadingPricingRequest;
use App\Models\Area;
use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\MeterReading;
use App\Models\ReadingEntrySetting;
use App\Models\StandingDiscount;
use App\Models\SubArea;
use App\Models\Subscription;
use App\Models\Tariff;
use App\Models\User;
use App\Notifications\ActionCompleted;
use App\Notifications\ReadingNeedsReapproval;
use App\Support\PrintSelection;
use App\Support\ReadingPrintData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class MeterReadingController extends Controller
{
    use FiltersDataTable;

    /**
     * Sortable sheet columns — the reading columns are computed per row for
     * the chosen week by withSheetColumns().
     */
    private const SORTABLE = ['full_name', 'account_number', 'meter_box_number', 'last_reading', 'current_reading', 'consumption', 'amount_due'];

    /**
     * The weekly reading sheet: one row per active subscription for the
     * chosen week, with their last reading, this week's reading (if
     * entered), and what that week costs them.
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorize('viewAny', MeterReading::class);

        $actor = auth()->user();
        $weekBranchId = $this->sheetBranchId($request, $actor);
        $canBrowseWeeks = $actor->can('browseWeeks', MeterReading::class);
        $weekStart = $this->selectedWeek($request, $weekBranchId, $canBrowseWeeks);
        $week = $weekStart->toDateString();

        $query = $this->subscriptionsInScope($actor)
            ->with([
                'tariff',
                'circuitBreaker',
                'standingDiscount',
                'meterBox.subArea',
                'meterReadings' => fn ($q) => $q->chaperone()->withLaterReadingsCount()->whereDate('week_start', '<=', $week)->orderByDesc('week_start')->limit(2)
                    ->with(['recordedBy:id,name', 'approvedBy:id,name', 'chargeLine:id,meter_reading_id']),
            ])
            ->withExists(['meterReadings as has_later_week' => fn ($q) => $q->whereDate('week_start', '>', $week)]);
        if ($request->boolean('print')) {
            $query->with('branch:id,name');
            $selected = PrintSelection::ids($request, '/meter-readings');
            if ($selected !== null) {
                $query->whereHas('meterReadings', fn (Builder $readings): Builder => $readings->whereKey($selected)->whereDate('week_start', $week));
            }
        }

        $this->withSheetColumns($query, $week);
        $this->applyMeterBoxSort($query, $request);
        $this->applyDataTableFilters($query, $request, ['full_name', 'subscription_name', 'account_number', 'phone', 'subscription_phone'], self::SORTABLE, 'full_name');
        $query->orderBy('subscriptions.id');
        $this->applyDataTableFilterSelects($query, $request, ['branch_id', 'meter_box_id', 'tariff_id']);
        $this->applyMeterBoxNameFilter($query, $request);
        $this->applySheetFilters($query, $request, $week, includeStatus: false);
        $summaryQuery = clone $query;
        $this->applySheetStatusFilters($query, $request, $week);

        $enteredThisWeek = fn (Builder $q) => $q->whereDate('week_start', $week);

        $rows = fn () => $query->paginate($this->dataTablePerPage($request, 25))
            ->withQueryString()
            ->through(fn (Subscription $subscription) => $this->sheetRow($subscription, $weekStart, $actor, $request->boolean('print')));

        if ($request->boolean('print')) {
            $weekEnd = MeterReading::weekEndFor($weekStart, $weekBranchId);
            $context = [
                'templatePage' => '/meter-readings',
                'title' => 'القراءات الأسبوعية',
                'branchName' => $actor->workBranch()?->name ?? 'كل الفروع',
                'readingDate' => $weekEnd->toDateString(),
                'readingDayName' => $weekEnd->locale('ar')->dayName,
                'weekStart' => $week,
                'weekEnd' => $weekEnd->toDateString(),
                'sourceUrl' => route('meter-readings.index'),
            ];

            return Inertia::render('Print/ReadingReport', ['printData' => fn (): array => ReadingPrintData::sheet($rows(), $context, $this->seesWhoRecorded($actor))]);
        }

        $scope = $this->subscriptionsInScope($actor);
        $canApprove = $actor->can('approveAny', MeterReading::class);

        return Inertia::render('MeterReadings/Index', [
            'rows' => $rows,
            'week' => $week,
            'weekEnd' => MeterReading::weekEndFor($weekStart, $weekBranchId)->toDateString(),
            // The weeks back to the first reading (at least fourteen months), for the year, month and week pickers;
            // only the latest one for someone who may not browse earlier weeks.
            'weekOptions' => fn () => MeterReading::recentWeekOptions($weekBranchId, $canBrowseWeeks ? $this->weeksToOffer($actor, $weekBranchId) : 1),
            'summary' => fn () => [
                'total' => (clone $scope)->count(),
                'entered' => (clone $scope)->whereHas('meterReadings', $enteredThisWeek)->count(),
                'amountDue' => number_format((float) MeterReading::query()
                    ->whereIn('subscription_id', (clone $scope)->select('id'))
                    ->whereDate('week_start', $week)
                    ->sum('amount_due'), 2, '.', ''),
            ],
            'canRecord' => $actor->can('createOnReadingDay', MeterReading::class),
            'canApprove' => $canApprove,
            'seesWhoRecorded' => $this->seesWhoRecorded($actor),
            'statusSummary' => fn () => $this->statusSummary($summaryQuery, $week),
            'pendingApproval' => fn () => $canApprove ? $this->pendingApprovalSummary($request, $actor, $week) : null,
            // The actor could record now, just not in this earlier week.
            'weekIsViewOnly' => $actor->can('createOnReadingDay', MeterReading::class) && ! $actor->can('createOnReadingDay', [MeterReading::class, $weekStart]),
            'entryWindow' => $this->entryWindow($actor),
            'filters' => $this->dataTableState($request, 'full_name', 'asc', 25),
            'filterOptions' => fn () => $this->filterOptions($actor, $request),
        ]);
    }

    /**
     * Store a newly created resource in storage. The reading records the
     * meter and what the week costs, with the subscription's standing
     * discount taken off, but does not charge the subscription's balance.
     */
    public function store(StoreMeterReadingRequest $request): RedirectResponse
    {
        $subscription = Subscription::with(['tariff', 'circuitBreaker', 'standingDiscount'])->findOrFail($request->integer('subscription_id'));
        $weekStart = $request->weekStart();
        $previousReading = $subscription->previousReadingBefore($weekStart);
        $currentReading = $request->float('current_reading');
        $consumption = MeterReading::consumptionBetween($previousReading, $currentReading);
        $usualConsumption = MeterReading::usualConsumptionIfUnusual($subscription, $consumption, $weekStart);
        $unitPrice = $subscription->kilowattPrice();
        $minimumPayment = $subscription->weeklyMinimumPayment();
        $discount = $subscription->standingDiscount;

        MeterReading::create([
            'subscription_id' => $subscription->id,
            'branch_id' => $subscription->branch_id,
            'week_start' => $weekStart,
            'week_end' => MeterReading::weekEndFor($weekStart, $subscription->branch_id),
            'previous_reading' => $previousReading,
            'current_reading' => $currentReading,
            'consumption' => $consumption,
            'usual_consumption' => $usualConsumption,
            'unit_price' => $unitPrice,
            'minimum_payment' => $minimumPayment,
            'discount_method' => $discount?->method,
            'discount_value' => $discount?->value,
            'discount_segment' => $discount?->segment,
            ...MeterReading::chargesFor($consumption, $unitPrice, $minimumPayment, $discount?->method, $discount?->value),
            'status' => MeterReadingStatus::Pending,
            'recorded_by' => $request->user()->id,
            'notes' => $request->input('notes'),
        ]);

        $request->user()->notify(new ActionCompleted('meter-reading-created', $subscription->displayName()));

        return back()->with('status', $usualConsumption === null ? 'meter-reading-created' : 'meter-reading-created-unusual');
    }

    /**
     * Correct a reading, recalculating its charges from the price and
     * minimum captured when it was first recorded. Correcting an approved
     * reading sends it back for approval and tells the people who approve —
     * unless the corrector may approve it and asks to (`approve`, as the
     * statement's form does), when it is approved again at once and billed
     * at the corrected amount, all or nothing. A last reading edited after
     * the week following it was entered carries that week along: it starts
     * from the corrected reading and is recalculated, going back to review
     * if it was approved.
     *
     * Edited as the last reading (`as_last_reading`, its pencil) by whoever
     * may, the edit is confirmed at once: each of the two weeks that was
     * approved is approved again by them, the corrected week before the week
     * after it, so the new charges follow each other on the statement in
     * week order and nothing waits for a second approval. The week after it
     * is recalculated from its reading typed again with the edit
     * (`next_current_reading`), never from the number it had. A week the
     * edit makes unusual needs `confirm_unusual`; without it nothing is saved.
     */
    public function update(UpdateMeterReadingRequest $request, MeterReading $meterReading): RedirectResponse
    {
        $actor = $request->user();
        $nextReading = $request->nextReading();
        $confirmedAtOnce = $request->boolean('as_last_reading') && $actor->can('updateAsLastReading', $meterReading);
        [$wentBackToReview, $approvedAgain, $nextWentBackToReview, $nextApprovedAgain] = DB::transaction(function () use ($request, $meterReading, $nextReading, $actor, $confirmedAtOnce): array {
            $wentBackToReview = $meterReading->correct(
                $request->float('current_reading'),
                $request->has('notes') ? $request->input('notes') : $meterReading->notes,
                $actor,
            );
            $nextWentBackToReview = $nextReading?->startFrom($meterReading->current_reading, $actor, $request->nextCurrentReading()) ?? false;

            if ($confirmedAtOnce) {
                return [
                    $wentBackToReview,
                    $wentBackToReview && $this->approveCorrectedLastReading($meterReading, $actor, $request->boolean('confirm_unusual')),
                    $nextWentBackToReview,
                    $nextWentBackToReview && $this->approveCorrectedLastReading($nextReading, $actor, $request->boolean('confirm_unusual')),
                ];
            }

            // A corrected reading that is now unusual waits for review unless the corrector confirms it.
            $approvedAgain = $wentBackToReview
                && $request->boolean('approve')
                && $actor->can('approve', $meterReading)
                && $meterReading->approve($actor, $request->boolean('confirm_unusual'));

            return [$wentBackToReview, $approvedAgain, $nextWentBackToReview, false];
        });

        $actor->notify(new ActionCompleted('meter-reading-updated', $meterReading->subscription->displayName()));

        if (($wentBackToReview && ! $approvedAgain) || ($nextWentBackToReview && ! $nextApprovedAgain)) {
            Notification::send(
                $meterReading->approvers()->reject(fn (User $approver) => $approver->is($actor)),
                new ReadingNeedsReapproval($meterReading->subscription->displayName(), $actor->name),
            );
        }

        return back()->with('status', match (true) {
            $confirmedAtOnce && ($approvedAgain || $nextApprovedAgain) => 'meter-reading-last-corrected-approved',
            $approvedAgain && ! $nextWentBackToReview => 'meter-reading-corrected-approved',
            $wentBackToReview || $nextWentBackToReview => 'meter-reading-reopened',
            $nextReading !== null => 'meter-reading-last-updated',
            default => 'meter-reading-updated',
        });
    }

    public function previewAdjustment(AdjustLastReadingRequest $request, MeterReading $meterReading): JsonResponse
    {
        $newReading = $request->float('current_reading');
        $reset = $request->isMeterChange();
        $after = $reset ? [
            'consumption' => $meterReading->consumption, 'amount_due' => $meterReading->amount_due, 'unusual' => false,
        ] : $meterReading->recalculatedBetween($meterReading->previous_reading, $newReading);
        $next = $meterReading->subscription->meterReadings()->where('week_start', '>', $meterReading->week_start)->first();

        return response()->json([
            'reset' => $reset, 'before' => $meterReading->nextPreviousReading(), 'after' => $newReading,
            'weeks' => [$this->previewedWeek($meterReading, $meterReading->previous_reading, $reset ? $meterReading->current_reading : $newReading, $after)],
            'clearsCurrentReading' => $next !== null,
            'removedCurrentAmount' => $next !== null && ! $next->isPending() ? $next->amount_due : '0.00',
        ]);
    }

    public function adjustLastReading(AdjustLastReadingRequest $request, MeterReading $meterReading): RedirectResponse
    {
        $actor = $request->user();
        DB::transaction(function () use ($request, $meterReading, $actor): void {
            $meterReading->subscription()->lockForUpdate()->firstOrFail();
            $reading = MeterReading::query()->lockForUpdate()->findOrFail($meterReading->id);
            $this->authorize('adjustLastReading', $reading);
            if (round($reading->nextPreviousReading(), 2) !== round($request->float('expected_reading'), 2)) {
                throw ValidationException::withMessages(['current_reading' => 'تغيّرت القراءة؛ حدّث الصفحة وراجع القيمة الجديدة قبل التعديل.']);
            }

            $newReading = $request->float('current_reading');
            if (! $request->isMeterChange() && round($newReading, 2) === round($reading->nextPreviousReading(), 2)) {
                return;
            }

            if ($request->isMeterChange()) {
                $reading->recordAdjustment('baseline_reset', ['previousReading' => $reading->nextPreviousReading()], ['previousReading' => $newReading], $actor, $request->validated('reason'));
                $reading->update(['next_previous_reading' => $newReading]);
            } else {
                $wasApproved = $reading->correct($newReading, $reading->notes, $actor);
                $reading->update(['next_previous_reading' => null]);
                if ($wasApproved) {
                    $this->approveCorrectedLastReading($reading, $actor, $request->boolean('confirm_unusual'));
                }
            }

            $next = $reading->subscription->meterReadings()->where('week_start', '>', $reading->week_start)->lockForUpdate()->first();
            $next?->requireCurrentReadingAgain($newReading, $actor);
        });

        $actor->notify(new ActionCompleted('meter-reading-updated', $meterReading->subscription->displayName()));

        return back()->with('status', 'meter-reading-updated');
    }

    public function updatePricing(UpdateReadingPricingRequest $request, MeterReading $meterReading): RedirectResponse
    {
        $actor = $request->user();
        $reopened = DB::transaction(function () use ($request, $meterReading, $actor): bool {
            $meterReading->subscription()->lockForUpdate()->firstOrFail();
            $reading = MeterReading::query()->lockForUpdate()->findOrFail($meterReading->id);
            foreach (['unit_price' => 'updateUnitPrice', 'minimum_payment' => 'updateMinimumPayment'] as $field => $ability) {
                if ($request->has($field)) {
                    $this->authorize($ability, $reading);
                }
            }
            $before = $reading->only(['unit_price', 'minimum_payment']);
            $reading->fill($request->validated());
            if (! $reading->isDirty(['unit_price', 'minimum_payment'])) {
                return false;
            }
            $reopened = $reading->correct($reading->current_reading, $reading->notes, $actor);
            $reading->recordAdjustment('pricing', $before, $reading->only(['unit_price', 'minimum_payment']), $actor);

            return $reopened;
        });
        if ($reopened) {
            Notification::send($meterReading->approvers()->reject(fn (User $approver) => $approver->is($actor)), new ReadingNeedsReapproval($meterReading->subscription->displayName(), $actor->name));
        }

        return back()->with('status', $reopened ? 'meter-reading-reopened' : 'meter-reading-updated');
    }

    /**
     * Approve again a week sent back to review by editing the last reading,
     * as part of confirming that edit. A week the edit made unusual is held
     * until the editor confirms it, and then nothing of the edit is saved.
     *
     * @throws ValidationException when the week is unusual and not confirmed
     */
    private function approveCorrectedLastReading(MeterReading $reading, User $actor, bool $confirmUnusual): bool
    {
        if (! $reading->approve($actor, $confirmUnusual)) {
            throw ValidationException::withMessages([
                'confirm_unusual' => 'استهلاك أسبوع '.$reading->week_start->format('d/m').' بعد التعديل ('.rtrim(rtrim(number_format((float) $reading->consumption, 2, '.', ''), '0'), '.').' كيلو) أعلى بكثير من المعتاد؛ أكّد أنه صحيح ليُعتمد.',
            ]);
        }

        return true;
    }

    /**
     * What editing a last reading to `current_reading` would do, shown
     * before the edit is confirmed: for its week and, once entered, the week
     * after it that starts from it, the readings, consumption and amount due
     * before and after, whether the week is approved (and so approved again
     * at once on confirming) and whether the edit makes it unusual. The week
     * after it is worked out once its reading is typed again
     * (`next_current_reading`); until then its `after` is null.
     */
    public function previewLastReading(PreviewLastReadingRequest $request, MeterReading $meterReading): JsonResponse
    {
        $newReading = $request->float('current_reading');
        $nextReading = $request->nextReading();
        $nextCurrentReading = $request->nextCurrentReading();
        $corrected = $meterReading->recalculatedBetween($meterReading->previous_reading, $newReading);
        $weeks = [$this->previewedWeek($meterReading, $meterReading->previous_reading, $newReading, $corrected)];

        if ($nextReading !== null) {
            $weeks[] = $this->previewedWeek($nextReading, $newReading, $nextCurrentReading, $nextCurrentReading === null ? null : $nextReading->recalculatedBetween(
                $newReading,
                $nextCurrentReading,
                [$meterReading->id => $corrected['consumption']],
            ));
        }

        return response()->json(['weeks' => $weeks]);
    }

    /**
     * One week of the last reading's preview.
     *
     * @param  array{consumption: float, amount_due: string, unusual: bool}|null  $after  null until its reading is typed again
     * @return array{id: int, weekStart: string, weekEnd: ?string, isApproved: bool, unusual: bool, before: array<string, float|string>, after: array<string, float|string>|null}
     */
    private function previewedWeek(MeterReading $reading, float $previousReading, ?float $currentReading, ?array $after): array
    {
        return [
            'id' => $reading->id,
            'weekStart' => $reading->week_start->toDateString(),
            'weekEnd' => $reading->week_end?->toDateString(),
            'isApproved' => ! $reading->isPending(),
            'unusual' => $after['unusual'] ?? false,
            'before' => [
                'previousReading' => $reading->previous_reading,
                'currentReading' => $reading->requires_reentry ? null : $reading->current_reading,
                'consumption' => (float) $reading->consumption,
                'amountDue' => $reading->amount_due,
            ],
            'after' => $after === null ? null : [
                'previousReading' => $previousReading,
                'currentReading' => $currentReading,
                'consumption' => $after['consumption'],
                'amountDue' => $after['amount_due'],
            ],
        ];
    }

    /**
     * Approve the ticked readings, or `all` pending readings of the given
     * week among the subscriptions matching the sheet's search and filters.
     * Approving locks a reading and charges it to the subscription's
     * transactions; readings the actor may not approve, or that are no
     * longer pending, are skipped.
     */
    public function approve(ApproveMeterReadingsRequest $request): RedirectResponse
    {
        $actor = $request->user();
        $query = $request->boolean('all')
            ? $this->pendingInSheet($request, $actor, MeterReading::weekStartFor($request->date('week'), $this->sheetBranchId($request, $actor))->toDateString())
            : $this->pendingReadings($actor)->whereKey($request->validated('reading_ids'));

        // An unusual reading is only approved when ticked on its own and confirmed, never as part of "all".
        $confirmUnusual = ! $request->boolean('all') && $request->boolean('confirm_unusual');
        $approved = $heldBack = 0;
        $query->with('subscription')->chunkById(200, function (Collection $readings) use ($actor, $confirmUnusual, &$approved, &$heldBack): void {
            foreach ($readings as $reading) {
                if ($reading->approve($actor, $confirmUnusual)) {
                    $approved++;
                } elseif ($reading->isUnusual() && ! $confirmUnusual) {
                    $heldBack++;
                }
            }
        });

        if ($approved === 0) {
            return back()->withErrors(['reading_ids' => $heldBack > 0
                ? 'القراءات المختارة استهلاكها أعلى بكثير من المعتاد؛ راجعها، ثم حدّدها واعتمدها بعد تأكيدها.'
                : 'لا توجد قراءات بانتظار الاعتماد ضمن اختيارك.']);
        }

        $actor->notify(new ActionCompleted('meter-readings-approved', "عدد القراءات: {$approved}"));

        return back()->with('status', $heldBack > 0 ? 'meter-readings-approved-partly' : 'meter-readings-approved');
    }

    /**
     * How many of the week's readings on the sheet (search and filters
     * applied) still wait for approval, and what they add up to.
     *
     * @return array{count: int, unusualCount: int, amountDue: string}
     */
    private function pendingApprovalSummary(Request $request, User $actor, string $week): array
    {
        $pending = $this->pendingInSheet($request, $actor, $week);

        return [
            'count' => (clone $pending)->count(),
            'unusualCount' => (clone $pending)->whereNotNull('usual_consumption')->count(),
            'amountDue' => number_format((float) $pending->sum('amount_due'), 2, '.', ''),
        ];
    }

    /**
     * Pending readings in the actor's branch (every branch for the Super Admin).
     *
     * @return Builder<MeterReading>
     */
    private function pendingReadings(User $actor): Builder
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
    private function pendingInSheet(Request $request, User $actor, string $week): Builder
    {
        $subscriptions = $this->subscriptionsInScope($actor);
        $this->applyDataTableFilters($subscriptions, $request, ['full_name', 'subscription_name', 'account_number', 'phone', 'subscription_phone'], [], 'full_name');
        $this->applyDataTableFilterSelects($subscriptions, $request, ['branch_id', 'meter_box_id', 'tariff_id']);
        $this->applyMeterBoxNameFilter($subscriptions, $request);
        $this->applySheetFilters($subscriptions, $request, $week);

        return $this->pendingReadings($actor)
            ->whereDate('week_start', $week)
            ->whereIn('subscription_id', $subscriptions->reorder()->select('subscriptions.id'));
    }

    /**
     * Whether the reading entry window of the actor's branch is open, and
     * whether it restricts this actor at all: only people who enter readings
     * are held to it, and the Super Admin may enter them any time.
     *
     * @return array{isOpen: bool, appliesToActor: bool, openDays: array<int, int>, opensAt: string, closesAt: string, nextOpensOn: ?string}
     */
    private function entryWindow(User $actor): array
    {
        $setting = ReadingEntrySetting::forBranch($actor->workBranchId());

        return [
            'isOpen' => $setting->isOpenOnReadingDay(),
            'appliesToActor' => ! $actor->isSuperAdmin() && $actor->hasPermission(PermissionKey::RecordMeterReadings),
            'openDays' => $setting->mode === ReadingEntryMode::Automatic
                ? array_values(array_intersect(array_map('intval', $setting->open_days), [$setting->reading_day]))
                : [],
            'opensAt' => substr($setting->opens_at, 0, 5),
            'closesAt' => substr($setting->closes_at, 0, 5),
            'nextOpensOn' => $setting->nextOpeningOnReadingDay()?->toDateString(),
        ];
    }

    /**
     * Active subscriptions the actor may see readings for.
     *
     * @return Builder<Subscription>
     */
    private function subscriptionsInScope(User $actor): Builder
    {
        return Subscription::query()
            ->visibleTo($actor)
            ->where('status', SubscriptionStatus::Active);
    }

    /**
     * Add each row's reading values for the week as selectable (and so
     * sortable) columns: the meter box number, the last reading the week
     * starts from, and — once entered — the new reading, consumption and
     * amount to pay.
     */
    private function withSheetColumns(Builder $query, string $week): void
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
    private function applyMeterBoxSort(Builder $query, Request $request): void
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
    private function sheetBranchId(Request $request, User $actor): ?int
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
    private function selectedWeek(Request $request, ?int $branchId, bool $canBrowseWeeks): Carbon
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
    private function weeksToOffer(User $actor, ?int $branchId): int
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
    private function applySheetFilters(Builder $query, Request $request, string $week, bool $includeStatus = true): void
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

    private function applySheetStatusFilters(Builder $query, Request $request, string $week): void
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

    /**
     * Counts for the status tabs retain search and location filters, without
     * narrowing the other tabs to the currently selected status or page.
     *
     * @return array{total: int, pending: int, approved: int, missing: int}
     */
    private function statusSummary(Builder $query, string $week): array
    {
        $counts = MeterReading::query()
            ->whereIn('subscription_id', (clone $query)->reorder()->select('subscriptions.id'))
            ->whereDate('week_start', $week)
            ->selectRaw('status, COUNT(*) as reading_count')
            ->groupBy('status')
            ->toBase()->pluck('reading_count', 'status');
        $total = (clone $query)->count();
        $pending = (int) ($counts[MeterReadingStatus::Pending->value] ?? 0);
        $approved = (int) ($counts[MeterReadingStatus::Approved->value] ?? 0);

        return ['total' => $total, 'pending' => $pending, 'approved' => $approved, 'missing' => $total - $pending - $approved];
    }

    /**
     * Whether the actor sees who recorded and approved each reading on the
     * sheet: those who may read the audit log (the Super Admin, and Branch
     * Admins by default), not the staff who only enter the readings.
     */
    private function seesWhoRecorded(User $actor): bool
    {
        return $actor->hasPermission(PermissionKey::ViewTransactionAudit);
    }

    /**
     * One sheet row. A reading already entered for the week shows the
     * prices and standing discount captured with it; otherwise the
     * subscription's current price, minimum and discount are shown for the
     * live calculation.
     *
     * @return array<string, mixed>
     */
    private function sheetRow(Subscription $subscription, Carbon $weekStart, User $actor, bool $printing = false): array
    {
        $reading = $subscription->meterReadings->first(fn (MeterReading $r) => $r->week_start->equalTo($weekStart));
        $lastBefore = $subscription->meterReadings->first(fn (MeterReading $r) => $r->week_start->lessThan($weekStart));
        [$discountMethod, $discountValue, $discountSegment] = $reading
            ? [$reading->discount_method, $reading->discount_value, $reading->discount_segment]
            : [$subscription->standingDiscount?->method, $subscription->standingDiscount?->value, $subscription->standingDiscount?->segment];

        return [
            ...($printing ? ['printDetails' => ['branchName' => $subscription->branch?->name, 'tariffName' => __($subscription->tariff->category->label()), 'breakerAmpere' => $subscription->circuitBreaker?->ampere, 'notes' => $reading?->notes]] : []),
            'id' => $subscription->id,
            'accountNumber' => $subscription->account_number,
            'fullName' => $subscription->displayName(),
            'meterBoxNumber' => $subscription->meterBox?->box_number,
            'meterBoxName' => $subscription->meterBox?->displayName(),
            'phone' => $subscription->contactPhone(),
            'subAreaName' => $subscription->meterBox?->subArea?->name,
            'previousReading' => $reading?->previous_reading ?? ($lastBefore?->nextPreviousReading() ?? (float) ($subscription->initial_reading ?? 0)),
            'unitPrice' => (string) ($reading?->unit_price ?? $subscription->kilowattPrice()),
            'minimumPayment' => (string) ($reading?->minimum_payment ?? $subscription->weeklyMinimumPayment()),
            'discount' => $discountMethod ? [
                'method' => $discountMethod->value,
                'value' => $discountValue,
                'terms' => StandingDiscount::termsFor($discountMethod, $discountValue),
                'segment' => $discountSegment,
            ] : null,
            'reading' => $reading ? [
                'id' => $reading->id,
                'currentReading' => $reading->requires_reentry ? null : $reading->current_reading,
                'requiresReentry' => $reading->requires_reentry,
                'consumption' => $reading->consumption,
                'readingFee' => $reading->reading_fee,
                'discountAmount' => $reading->discount_amount,
                'amountDue' => $reading->amount_due,
                'status' => $reading->status->value,
                'statusLabel' => __($reading->status->label()),
                // An approved reading whose bill was cancelled from the statement is not billed any more.
                'billed' => $reading->chargeLine !== null,
                // Far above what the subscription usually uses: it is approved only after being looked at and confirmed.
                'unusual' => $reading->isUnusual() ? ['usual' => $reading->usual_consumption] : null,
            ] + ($this->seesWhoRecorded($actor) ? [
                'recordedByName' => $reading->recordedBy?->name,
                'recordedAt' => $reading->created_at?->timezone(config('app.business_timezone'))->format('d/m H:i'),
                'recordedSource' => $reading->mobile_operation_id !== null ? 'app' : 'web',
                'approvedByName' => $reading->approvedBy?->name,
                'approvedAt' => $reading->approved_at?->timezone(config('app.business_timezone'))->format('d/m H:i'),
            ] : []) : null,
            // The reading the week starts from, when one was entered: editable as the last reading while no week after this one has been.
            'lastReading' => $lastBefore ? [
                'id' => $lastBefore->id,
                'weekStart' => $lastBefore->week_start->toDateString(),
                'weekEnd' => $lastBefore->week_end?->toDateString(),
                'previousReading' => $lastBefore->previous_reading,
                'status' => $lastBefore->status->value,
                'canEdit' => ! $subscription->has_later_week && $actor->can('adjustLastReading', $lastBefore),
            ] : null,
            'hasLaterWeek' => (bool) $subscription->has_later_week,
            'canApprove' => $reading !== null && $actor->can('approve', $reading),
            'canEdit' => $reading
                ? ! $subscription->has_later_week && $actor->can('update', $reading)
                : ! $subscription->has_later_week && $actor->can('createOnReadingDay', [MeterReading::class, $weekStart]),
        ];
    }

    /**
     * The Filter menu's dropdown groups.
     *
     * @return array<int, array{key: string, label: string, options: array<int, array{value: string, label: string}>}>
     */
    private function filterOptions(User $actor, Request $request): array
    {
        $groups = [];

        if ($actor->isSuperAdmin()) {
            $groups[] = $this->branchFilterGroup();
            $groups[] = $this->areaFilterGroup(Area::orderBy('name')->get());
        }

        $groups[] = $this->subAreaFilterGroup(SubArea::visibleTo($actor)->orderBy('name')->get());

        array_push($groups, ...$this->meterBoxFilterGroupsFor($actor, $request));
        $groups[] = $this->circuitBreakerFilterGroup();

        $groups[] = $this->filterGroup('tariff_id', 'نوع الاشتراك', $this->modelOptions(
            Tariff::orderBy('category')->get(),
            fn (Tariff $tariff) => __($tariff->category->label()),
        ));

        $groups[] = $this->filterGroup('entry', 'حالة الإدخال', [
            ['value' => 'missing', 'label' => 'لم تُدخل بعد'],
            ['value' => 'entered', 'label' => 'تم الإدخال'],
        ]);

        $groups[] = $this->filterGroup('approval', 'حالة الاعتماد', [
            ['value' => MeterReadingStatus::Pending->value, 'label' => 'بانتظار الاعتماد'],
            ['value' => MeterReadingStatus::Approved->value, 'label' => 'معتمدة'],
        ]);

        return $groups;
    }
}
