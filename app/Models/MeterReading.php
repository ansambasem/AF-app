<?php

namespace App\Models;

use App\Enums\CorrectionReason;
use App\Enums\DiscountMethod;
use App\Enums\MeterReadingStatus;
use App\Enums\PermissionKey;
use App\Enums\UserRole;
use App\Models\Concerns\BelongsToBranch;
use Carbon\CarbonInterface;
use Database\Factories\MeterReadingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'subscription_id', 'branch_id', 'week_start', 'week_end', 'previous_reading', 'current_reading',
    'consumption', 'usual_consumption', 'unit_price', 'reading_fee', 'minimum_payment', 'discount_method', 'discount_value', 'discount_segment', 'discount_amount',
    'amount_due', 'status', 'recorded_by', 'notes', 'approved_by', 'approved_at', 'mobile_operation_id',
    'next_previous_reading', 'requires_reentry', 'adjustment_history',
])]
class MeterReading extends Model
{
    /** @use HasFactory<MeterReadingFactory> */
    use BelongsToBranch, HasFactory;

    protected function casts(): array
    {
        return [
            'next_previous_reading' => 'float',
            'requires_reentry' => 'boolean',
            'adjustment_history' => 'array',
            'week_start' => 'date',
            'week_end' => 'date',
            'previous_reading' => 'float',
            'current_reading' => 'float',
            'consumption' => 'float',
            'usual_consumption' => 'float',
            'status' => MeterReadingStatus::class,
            'unit_price' => 'decimal:2',
            'reading_fee' => 'decimal:2',
            'minimum_payment' => 'decimal:2',
            'discount_method' => DiscountMethod::class,
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'amount_due' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * The first day of the reading week containing the given date. Weeks end
     * on the branch's reading day (Thursday unless changed in its reading
     * schedule settings; the company's reading day for a branch without a
     * schedule of its own, or when no branch is given) and start the day
     * after the previous one.
     */
    public static function weekStartFor(CarbonInterface $date, Branch|int|null $branch): Carbon
    {
        return ReadingEntrySetting::forBranch($branch)->weekStartFor($date);
    }

    /**
     * The reading day that ends the reading week containing the given date.
     */
    public static function weekEndFor(CarbonInterface $date, Branch|int|null $branch): Carbon
    {
        return ReadingEntrySetting::forBranch($branch)->weekEndFor($date);
    }

    /**
     * The start of the latest week that has ended, counting today as its
     * last day if today is the reading day. "Today" is the business's local
     * date.
     */
    public static function latestEndedWeekStart(Branch|int|null $branch, ?CarbonInterface $at = null): Carbon
    {
        return ReadingEntrySetting::forBranch($branch)->latestEndedWeekStart($at);
    }

    /**
     * The latest ended week and the ones before it, newest first, as
     * select options (`value` the week's first day, `end` its last). Weeks
     * read on an earlier reading day keep their dates.
     *
     * @return array<int, array{value: string, label: string, end: string}>
     */
    public static function recentWeekOptions(Branch|int|null $branch, int $count = 8): array
    {
        $weekStart = self::latestEndedWeekStart($branch);
        $options = [];

        while (count($options) < $count) {
            $weekEnd = self::weekEndFor($weekStart, $branch);
            $options[] = [
                'value' => $weekStart->toDateString(),
                'label' => 'الأسبوع المنتهي في '.$weekEnd->locale('ar')->dayName.' '.$weekEnd->format('d-m-Y'),
                'end' => $weekEnd->toDateString(),
            ];
            $weekStart = self::weekStartFor($weekStart->copy()->subDay(), $branch);
        }

        return $options;
    }

    /**
     * The recent weeks of each branch the user may enter readings for, by
     * branch id (every branch for the Super Admin, whose list is in the
     * branches' name order), since each branch reads on its own weeks.
     *
     * @return array<int, array<int, array{value: string, label: string, end: string}>>
     */
    public static function recentWeekOptionsFor(User $user, int $count = 8): array
    {
        $branchIds = $user->isSuperAdmin() ? Branch::query()->orderBy('name')->pluck('id') : collect([$user->workBranchId()]);

        return $branchIds->mapWithKeys(fn (?int $branchId): array => [$branchId => self::recentWeekOptions($branchId, $count)])->all();
    }

    /**
     * Record the subscription's reading for the week, pending approval: from
     * the reading the week starts from, at their kilo price, minimum payment
     * and standing discount as they are now, so a later change never alters
     * it. It does not touch the subscription's balance until approved.
     * The subscription should come with its tariff, circuit breaker and
     * standing discount.
     */
    public static function recordFor(Subscription $subscription, CarbonInterface $weekStart, float $currentReading, User $recorder, ?string $notes, ?string $mobileOperationId = null): self
    {
        $weekStart = Carbon::instance($weekStart);
        if ($subscription->meterReadings()->where('week_start', '<', $weekStart)->where('requires_reentry', true)->exists()) {
            throw ValidationException::withMessages(['current_reading' => 'أدخل القراءة الحالية المعلّقة أولًا قبل تسجيل أسبوع جديد.']);
        }
        $previousReading = $subscription->previousReadingBefore($weekStart);
        $consumption = self::consumptionBetween($previousReading, $currentReading);
        $discount = $subscription->standingDiscount;
        $unitPrice = $subscription->kilowattPrice();
        $minimumPayment = $subscription->weeklyMinimumPayment();

        return self::create([
            'subscription_id' => $subscription->id,
            'branch_id' => $subscription->branch_id,
            'week_start' => $weekStart,
            'week_end' => self::weekEndFor($weekStart, $subscription->branch_id),
            'previous_reading' => $previousReading,
            'current_reading' => $currentReading,
            'consumption' => $consumption,
            'usual_consumption' => self::usualConsumptionIfUnusual($subscription, $consumption, $weekStart),
            'unit_price' => $unitPrice,
            'minimum_payment' => $minimumPayment,
            'discount_method' => $discount?->method,
            'discount_value' => $discount?->value,
            'discount_segment' => $discount?->segment,
            ...self::chargesFor($consumption, $unitPrice, $minimumPayment, $discount?->method, $discount?->value),
            'status' => MeterReadingStatus::Pending,
            'recorded_by' => $recorder->id,
            'notes' => $notes,
            'mobile_operation_id' => $mobileOperationId,
        ]);
    }

    /**
     * The kWh used between two meter readings, rounded to the two decimal
     * places readings are kept to.
     */
    public static function consumptionBetween(float $previousReading, float $currentReading): float
    {
        return round($currentReading - $previousReading, 2);
    }

    /**
     * Whether a week's consumption is more than any subscription could use:
     * it is refused when entered, as a mistyped reading.
     */
    public static function isImpossibleConsumption(float $consumption): bool
    {
        return $consumption > (float) config('powercollect.readings.max_weekly_kwh');
    }

    /**
     * What the subscription usually uses in a week: the average of its last
     * readings before the given week, or null while it has too few to say.
     * `$consumptionOverrides` ([reading id => kWh]) counts readings at the
     * consumption they would have after a correction not saved yet.
     *
     * @param  array<int, float>  $consumptionOverrides
     */
    public static function usualConsumptionOf(Subscription $subscription, CarbonInterface $weekStart, array $consumptionOverrides = []): ?float
    {
        $consumptions = self::query()
            ->where('subscription_id', $subscription->id)
            ->whereDate('week_start', '<', $weekStart->toDateString())
            ->orderByDesc('week_start')
            ->limit((int) config('powercollect.readings.usual_readings'))
            ->pluck('consumption', 'id')
            ->map(fn (mixed $consumption, int $id): float => $consumptionOverrides[$id] ?? (float) $consumption);

        return $consumptions->count() < (int) config('powercollect.readings.usual_minimum_readings')
            ? null
            : round((float) $consumptions->avg(), 2);
    }

    /**
     * The usual consumption to keep with a reading when this week's is far
     * above it — a multiple of it, and big enough to matter — so the reading
     * is flagged for review; null when it is nothing unusual. A subscription
     * with no usual yet has none to compare with (kept as 0), so only a
     * very large week is flagged.
     *
     * @param  array<int, float>  $consumptionOverrides  see usualConsumptionOf()
     */
    public static function usualConsumptionIfUnusual(Subscription $subscription, float $consumption, CarbonInterface $weekStart, array $consumptionOverrides = []): ?float
    {
        $usual = self::usualConsumptionOf($subscription, $weekStart, $consumptionOverrides);

        if ($usual === null) {
            return $consumption >= (float) config('powercollect.readings.unusual_without_history_kwh') ? 0.0 : null;
        }

        if ($consumption < (float) config('powercollect.readings.unusual_minimum_kwh')) {
            return null;
        }

        return $consumption > $usual * (float) config('powercollect.readings.unusual_multiplier') ? $usual : null;
    }

    /**
     * Whether the reading was flagged when it was entered: far above what
     * the subscription usually uses, so it needs a second look.
     */
    public function isUnusual(): bool
    {
        return $this->usual_consumption !== null;
    }

    /**
     * What a standing discount takes off a week's reading fee, in shekels:
     * a percentage of the fee, kilowatts of the consumption at the kilo
     * price, or shekels off the price of each kilo — never more than the fee.
     */
    public static function discountFor(DiscountMethod $method, float|string $value, float $consumption, float|string $unitPrice): float
    {
        $readingFee = round($consumption * (float) $unitPrice, 2);

        $discount = match ($method) {
            DiscountMethod::Percentage => $readingFee * (float) $value / 100,
            DiscountMethod::Kilowatt => min((float) $value, $consumption) * (float) $unitPrice,
            DiscountMethod::Shekel => $consumption * min((float) $value, (float) $unitPrice),
        };

        return min(round($discount, 2), $readingFee);
    }

    /**
     * What a week's consumption costs: consumption × kilowatt price, but
     * never less than the minimum payment. A subscription with a standing
     * discount pays the fee less the discount instead, with no minimum:
     * only the kilos the discount leaves are paid for. `discount_amount`
     * is what the discount took off the week's bill.
     *
     * @return array{reading_fee: string, discount_amount: string, amount_due: string}
     */
    public static function chargesFor(
        float $consumption,
        float|string $unitPrice,
        float|string $minimumPayment,
        ?DiscountMethod $discountMethod = null,
        float|string|null $discountValue = null,
    ): array {
        $readingFee = round($consumption * (float) $unitPrice, 2);
        $hasDiscount = $discountMethod !== null && $discountValue !== null;
        $discount = $hasDiscount ? self::discountFor($discountMethod, $discountValue, $consumption, $unitPrice) : 0.0;
        $amountDue = $hasDiscount ? round($readingFee - $discount, 2) : max($readingFee, (float) $minimumPayment);

        return [
            'reading_fee' => number_format($readingFee, 2, '.', ''),
            'discount_amount' => number_format($discount, 2, '.', ''),
            'amount_due' => number_format($amountDue, 2, '.', ''),
        ];
    }

    public function isPending(): bool
    {
        return $this->status === MeterReadingStatus::Pending;
    }

    /**
     * Approve the reading: it is locked from then on, and its amount is
     * charged to the subscription's transactions — the week's full bill, with
     * its standing discount beside it as a line of its own (خصم القراءات الأسبوعية). A
     * reading that is already approved is left as it is, and so is an unusual
     * one the approver has not confirmed. Returns whether this approved it.
     */
    public function approve(User $approver, bool $confirmUnusual = false): bool
    {
        return DB::transaction(function () use ($approver, $confirmUnusual): bool {
            $reading = self::query()->lockForUpdate()->findOrFail($this->id);

            if ($reading->requires_reentry || ! $reading->isPending() || ($reading->isUnusual() && ! $confirmUnusual)) {
                return false;
            }

            $reading->update([
                'status' => MeterReadingStatus::Approved,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            $reading->recordChargeLine($approver);
            $reading->recordDiscountLine($approver);

            $this->setRawAttributes($reading->getAttributes(), true);

            return true;
        });
    }

    /**
     * Send an approved reading back to review, as when its charge was
     * cancelled with the reading reopened. Its week can then be corrected,
     * and billed afresh once it is approved again. A reading still pending
     * is left as it is.
     */
    public function reopen(): void
    {
        if ($this->isPending()) {
            return;
        }

        $this->update(['status' => MeterReadingStatus::Pending, 'approved_by' => null, 'approved_at' => null]);
    }

    /**
     * Approve the reading again as it was, when the cancellation of its
     * charge is taken back and that charge stands once more: by whoever
     * billed it, at the time they did.
     */
    public function approveAgainAsBilled(SubscriptionTransaction $charge): void
    {
        if ($this->requires_reentry || ! $this->isPending()) {
            return;
        }

        $this->update(['status' => MeterReadingStatus::Approved, 'approved_by' => $charge->recorded_by, 'approved_at' => $charge->created_at]);
    }

    /**
     * Bill the reading with the subscription's standing discount as it is now
     * (none when it was stopped), at the prices the reading was recorded
     * with. An approved reading's lines are rebilled to match — the old ones
     * cancelled with a reversal, kept on the statement, and the new ones
     * recorded under them — so the subscription's transactions show the change
     * straight away; a pending one's shows when it is approved.
     */
    public function applyStandingDiscount(?StandingDiscount $discount, User $recorder): void
    {
        DB::transaction(function () use ($discount, $recorder): void {
            $reading = self::query()->lockForUpdate()->findOrFail($this->id);

            $reading->update([
                'discount_method' => $discount?->method,
                'discount_value' => $discount?->value,
                'discount_segment' => $discount?->segment,
                ...($reading->requires_reentry
                    ? ['reading_fee' => '0.00', 'discount_amount' => '0.00', 'amount_due' => '0.00']
                    : self::chargesFor($reading->consumption, $reading->unit_price, $reading->minimum_payment, $discount?->method, $discount?->value)),
            ]);

            if (! $reading->isPending()) {
                $charge = $reading->currentLine(SubscriptionTransaction::TYPE_METER_READING);
                $discountLine = $reading->currentLine(SubscriptionTransaction::TYPE_READING_DISCOUNT);
                $chargeNeedsRebilling = $charge && $charge->amount !== $reading->amountBeforeDiscount();

                if ($chargeNeedsRebilling) {
                    $charge->cancelForReading($recorder, CorrectionReason::StandingDiscountChanged);
                }

                $discountLine?->cancelForReading($recorder, CorrectionReason::StandingDiscountChanged);

                if ($chargeNeedsRebilling) {
                    $reading->recordChargeLine($recorder);
                }

                $reading->recordDiscountLine($recorder);
            }

            $this->setRawAttributes($reading->getAttributes(), true);
        });
    }

    /**
     * Charge the reading's week to the subscription's account: the full bill,
     * before its standing discount.
     */
    private function recordChargeLine(User $recorder): void
    {
        if ($this->sourceWasBilled($this->chargeSourceKey())) {
            return;
        }

        $this->subscription->transactions()->create([
            'recorded_by' => $recorder->id,
            'meter_reading_id' => $this->id,
            'corrects_id' => $this->lastCancelledLineId(SubscriptionTransaction::TYPE_METER_READING),
            'type' => SubscriptionTransaction::TYPE_METER_READING,
            'source_key' => $this->chargeSourceKey(),
            'amount' => $this->amountBeforeDiscount(),
            'currency_amount' => $this->amountBeforeDiscount(),
            'billed_consumption' => $this->consumption,
        ]);
    }

    /**
     * Take the reading's standing discount off the subscription's account as
     * a line of its own (خصم القراءات الأسبوعية) beside the reading's charge, naming the
     * customer segment it was given to in its details; a reading billed
     * without one takes nothing off.
     */
    private function recordDiscountLine(User $recorder): void
    {
        if ((float) $this->discount_amount <= 0
            || $this->sourceWasBilled($this->discountSourceKey())
            || $this->currentLine(SubscriptionTransaction::TYPE_METER_READING, lockForUpdate: true) === null) {
            return;
        }

        $this->subscription->transactions()->create([
            'recorded_by' => $recorder->id,
            'meter_reading_id' => $this->id,
            'corrects_id' => $this->lastCancelledLineId(SubscriptionTransaction::TYPE_READING_DISCOUNT),
            'type' => SubscriptionTransaction::TYPE_READING_DISCOUNT,
            'source_key' => $this->discountSourceKey(),
            'amount' => number_format(-(float) $this->discount_amount, 2, '.', ''),
            'currency_amount' => $this->discount_amount,
            'discount_method' => $this->discount_method,
            'discount_value' => $this->discount_value,
            'notes' => $this->discount_segment,
        ]);
    }

    /**
     * A line deleted by hand keeps its source key so this reading can never
     * bill that component again. Automatic reading corrections free the key
     * before recording their replacement.
     */
    private function sourceWasBilled(string $sourceKey): bool
    {
        return SubscriptionTransaction::query()->where('source_key', $sourceKey)->exists();
    }

    /**
     * The reading's charge or standing-discount line that stands on the
     * subscription's account, if it has been billed.
     */
    private function currentLine(string $type, bool $lockForUpdate = false): ?SubscriptionTransaction
    {
        return SubscriptionTransaction::query()
            ->where('meter_reading_id', $this->id)
            ->where('type', $type)
            ->whereNull('cancelled_at')
            ->when($lockForUpdate, fn (Builder $query): Builder => $query->lockForUpdate())
            ->first();
    }

    /**
     * The reading's last cancelled line of the given type that nothing has
     * replaced yet, which the line billed next corrects.
     */
    private function lastCancelledLineId(string $type): ?int
    {
        return SubscriptionTransaction::query()
            ->where('meter_reading_id', $this->id)
            ->where('type', $type)
            ->whereNotNull('cancelled_at')
            ->whereDoesntHave('correction')
            ->latest('id')
            ->value('id');
    }

    /**
     * Correct the reading and recalculate its charges at the prices and
     * standing discount captured when it was recorded. An approved reading
     * goes back to review: its charge and discount lines are cancelled with
     * a reversal — kept on the statement — until it is approved again, when
     * the new lines are recorded under them. Returns whether that happened.
     */
    public function correct(float $currentReading, ?string $notes, User $editor): bool
    {
        return DB::transaction(function () use ($currentReading, $notes, $editor): bool {
            $before = ['currentReading' => $this->requires_reentry ? null : $this->current_reading];
            $changed = $this->requires_reentry || round($currentReading, 2) !== round($this->current_reading, 2);
            $reopened = $this->recalculate($this->previous_reading, $currentReading, $notes, $editor);
            if ($changed) {
                $this->recordAdjustment('reading_correction', $before, ['currentReading' => $currentReading], $editor);
            }

            return $reopened;
        });
    }

    /** The next week's baseline can differ from this week's billed meter. */
    public function nextPreviousReading(): float
    {
        return $this->next_previous_reading ?? $this->current_reading;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function recordAdjustment(string $kind, array $before, array $after, User $editor, ?string $reason = null): void
    {
        $this->update(['adjustment_history' => [
            ...($this->adjustment_history ?? []),
            ['kind' => $kind, 'before' => $before, 'after' => $after, 'reason' => $reason,
                'userId' => $editor->id, 'userName' => $editor->name, 'at' => now()->toIso8601String()],
        ]]);
    }

    /** Keep the old entry in the audit trail, but never bill it from a new baseline. */
    public function requireCurrentReadingAgain(float $previousReading, User $editor): void
    {
        $this->recordAdjustment('current_reading_cleared', [
            'previousReading' => $this->previous_reading, 'currentReading' => $this->current_reading,
            'consumption' => $this->consumption, 'amountDue' => $this->amount_due,
        ], ['previousReading' => $previousReading, 'currentReading' => null], $editor);

        foreach ([SubscriptionTransaction::TYPE_METER_READING, SubscriptionTransaction::TYPE_READING_DISCOUNT] as $type) {
            $this->currentLine($type)?->cancelForReading($editor, CorrectionReason::ReadingCorrected);
        }

        $this->update([
            'previous_reading' => $previousReading, 'requires_reentry' => true,
            'consumption' => 0, 'reading_fee' => 0, 'amount_due' => 0, 'discount_amount' => 0,
            'usual_consumption' => null, 'status' => MeterReadingStatus::Pending,
            'approved_by' => null, 'approved_at' => null,
        ]);
    }

    /**
     * Start the reading from the corrected reading of the week before it —
     * its last reading, edited after this week was entered — at
     * `$currentReading` when this week's reading was entered again with the
     * edit, and recalculate it as correct() does: an approved one goes back
     * to review. Returns whether that happened; a reading already starting
     * there, at the same reading, is left as it is.
     */
    public function startFrom(float $previousReading, User $editor, ?float $currentReading = null): bool
    {
        $currentReading ??= $this->current_reading;

        if (round($previousReading, 2) === round($this->previous_reading, 2) && round($currentReading, 2) === round($this->current_reading, 2)) {
            return false;
        }

        return $this->recalculate($previousReading, $currentReading, $this->notes, $editor);
    }

    /**
     * What the reading would come to between the given readings, at the
     * prices and standing discount captured when it was recorded, without
     * changing it: its consumption, its amount due and whether it would be
     * flagged as unusual. `$consumptionOverrides` is as usualConsumptionOf().
     *
     * @param  array<int, float>  $consumptionOverrides
     * @return array{consumption: float, amount_due: string, unusual: bool}
     */
    public function recalculatedBetween(float $previousReading, float $currentReading, array $consumptionOverrides = []): array
    {
        $consumption = self::consumptionBetween($previousReading, $currentReading);

        return [
            'consumption' => $consumption,
            'amount_due' => self::chargesFor($consumption, $this->unit_price, $this->minimum_payment, $this->discount_method, $this->discount_value)['amount_due'],
            'unusual' => self::usualConsumptionIfUnusual($this->subscription, $consumption, $this->week_start, $consumptionOverrides) !== null,
        ];
    }

    /**
     * Recalculate the reading between the given readings at the prices and
     * standing discount captured when it was recorded, sending an approved
     * one back to review (see correct()).
     */
    private function recalculate(float $previousReading, float $currentReading, ?string $notes, User $editor): bool
    {
        return DB::transaction(function () use ($previousReading, $currentReading, $notes, $editor): bool {
            $wasApproved = ! $this->isPending();
            $consumption = self::consumptionBetween($previousReading, $currentReading);

            if ($wasApproved) {
                foreach ([SubscriptionTransaction::TYPE_METER_READING, SubscriptionTransaction::TYPE_READING_DISCOUNT] as $type) {
                    $this->currentLine($type)?->cancelForReading($editor, CorrectionReason::ReadingCorrected);
                }
            }

            $this->update([
                'previous_reading' => $previousReading,
                'current_reading' => $currentReading,
                'requires_reentry' => false,
                'consumption' => $consumption,
                'usual_consumption' => self::usualConsumptionIfUnusual($this->subscription, $consumption, $this->week_start),
                ...self::chargesFor($consumption, $this->unit_price, $this->minimum_payment, $this->discount_method, $this->discount_value),
                'notes' => $notes,
                ...($wasApproved ? ['status' => MeterReadingStatus::Pending, 'approved_by' => null, 'approved_at' => null] : []),
            ]);

            return $wasApproved;
        });
    }

    /**
     * The active users who may approve this reading: those holding the
     * permission in its branch, and every Super Admin.
     *
     * @return Collection<int, User>
     */
    public function approvers(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->where('role', UserRole::SuperAdmin)
                ->orWhere(fn (Builder $inBranch) => $inBranch
                    ->where('branch_id', $this->branch_id)
                    ->whereHas('permissions', fn (Builder $permission) => $permission->where('key', PermissionKey::ApproveMeterReadings->value))))
            ->get();
    }

    /**
     * The key of the transaction that charges this reading once approved.
     */
    public function chargeSourceKey(): string
    {
        return 'meter-reading:'.$this->id;
    }

    /**
     * The key of the transaction that takes this reading's standing
     * discount off once it is approved.
     */
    public function discountSourceKey(): string
    {
        return 'meter-reading-discount:'.$this->id;
    }

    /**
     * The week's bill before its standing discount: the reading fee, or
     * the minimum payment when that is more.
     */
    public function amountBeforeDiscount(): string
    {
        return number_format((float) $this->amount_due + (float) $this->discount_amount, 2, '.', '');
    }

    /** Batch the successor count for lists; mutations still query their freshly loaded reading. */
    public function scopeWithLaterReadingsCount(Builder $query): void
    {
        $laterModel = new static;
        $laterModel->setTable('later_readings');
        $laterReadings = $laterModel->newQuery()->from('meter_readings as later_readings')
            ->whereColumn('later_readings.subscription_id', 'meter_readings.subscription_id')
            ->whereColumn('later_readings.week_start', '>', 'meter_readings.week_start');
        $query->addSelect([
            'later_readings_count' => (clone $laterReadings)->selectRaw('COUNT(*)'),
            'next_reading_week_start' => (clone $laterReadings)->selectRaw('MIN(week_start)'),
        ]);
    }

    public function laterReadingsCount(): int
    {
        return array_key_exists('later_readings_count', $this->attributes)
            ? (int) $this->attributes['later_readings_count']
            : $this->subscription->meterReadings()->where('week_start', '>', $this->week_start)->count();
    }

    public function hasLaterReadingBefore(CarbonInterface $weekStart): bool
    {
        if (array_key_exists('next_reading_week_start', $this->attributes)) {
            $next = $this->attributes['next_reading_week_start'];

            return $next !== null && $next < $weekStart->toDateString();
        }

        return $this->subscription->meterReadings()->where('week_start', '>', $this->week_start)
            ->where('week_start', '<', $weekStart)->exists();
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * The reading's charge on the subscription's account while it stands:
     * none for a pending reading, or for one whose bill was cancelled.
     */
    public function chargeLine(): HasOne
    {
        return $this->hasOne(SubscriptionTransaction::class)
            ->where('type', SubscriptionTransaction::TYPE_METER_READING)
            ->whereNull('cancelled_at');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
