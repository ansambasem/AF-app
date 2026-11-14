<?php

namespace App\Support;

use App\Models\BillingPeriod;
use App\Models\Branch;
use App\Models\Closing;
use App\Models\ReadingCycle;
use App\Models\ReadingDistribution;
use App\Models\SubscriberPeriodBalance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The branches' billing periods: the one open now, the one a date or a
 * reading week belongs to, creating a period ahead, closing the open one,
 * and approving a closed one. A period stays open, however long, until
 * someone allowed to closes it by hand; nothing closes it by itself. A
 * period scheduled ahead opens on its first day once no other is open (on
 * the scheduler's minute or the first request of the day, as shared hosting
 * may run no scheduler), or as soon as the open one is closed if its first
 * day has come. Payments are taken only while a period is open
 * (paymentLockReason()). Read once per request or job (it is registered as
 * scoped).
 */
class BillingPeriods
{
    /** @var array<int, Collection<int, BillingPeriod>> */
    private array $loaded = [];

    /** @var array<int, bool> */
    private array $refreshed = [];

    private ?bool $tableExists = null;

    /**
     * Whether the branch works by billing periods: once it has one, its
     * reading weeks are its periods.
     */
    public function uses(?int $branchId): bool
    {
        return $branchId !== null && $this->of($branchId)->isNotEmpty();
    }

    /** The branch's open period, opening one scheduled for today first. */
    public function open(?int $branchId): ?BillingPeriod
    {
        if ($branchId === null) {
            return null;
        }
        $this->refresh($branchId);

        return $this->of($branchId)->first(fn (BillingPeriod $period): bool => $period->isOpen());
    }

    /**
     * The period of the branch that a business date falls in: the latest one
     * starting on or before it (a period runs until the next one starts), or
     * null before the first.
     */
    public function containing(int $branchId, CarbonInterface $date): ?BillingPeriod
    {
        $day = $date->toDateString();

        return $this->of($branchId)->filter(fn (BillingPeriod $period): bool => $period->opened_at !== null && $period->starts_on->toDateString() <= $day)
            ->sortByDesc(fn (BillingPeriod $period): string => $period->starts_on->toDateString())->first();
    }

    public function find(?int $periodId): ?BillingPeriod
    {
        if ($periodId === null) {
            return null;
        }

        foreach ($this->loaded as $periods) {
            if ($period = $periods->firstWhere('id', $periodId)) {
                return $period;
            }
        }

        return BillingPeriod::withoutGlobalScopes()->find($periodId);
    }

    /**
     * Every period of the branch, oldest first.
     *
     * @return Collection<int, BillingPeriod>
     */
    public function of(int $branchId): Collection
    {
        // Until its migration has run (the moments of a deploy), no branch has periods.
        $this->tableExists ??= Schema::hasTable('billing_periods');

        return $this->loaded[$branchId] ??= $this->tableExists
            ? BillingPeriod::withoutGlobalScopes()->where('branch_id', $branchId)->orderBy('starts_on')->get()
            : new Collection;
    }

    /**
     * Open the branch's period scheduled for today or earlier, and prepare
     * its reading cycle, when no period of the branch is open: an open
     * period is never closed here, only by hand (close()).
     */
    public function refresh(int $branchId): void
    {
        if ($this->refreshed[$branchId] ?? false) {
            return;
        }
        $this->refreshed[$branchId] = true;
        $today = DailySeries::today()->toDateString();
        $periods = $this->of($branchId);
        if ($periods->contains(fn (BillingPeriod $period): bool => $period->isOpen())
            || ! $periods->contains(fn (BillingPeriod $period): bool => $period->opened_at === null && $period->starts_on->toDateString() <= $today)) {
            return;
        }

        DB::transaction(function () use ($branchId, $today): void {
            Branch::withoutGlobalScopes()->whereKey($branchId)->lockForUpdate()->firstOrFail();
            if (BillingPeriod::withoutGlobalScopes()->where('branch_id', $branchId)->whereNotNull('opened_at')->whereNull('closed_at')->exists()) {
                return;
            }
            $due = BillingPeriod::withoutGlobalScopes()->where('branch_id', $branchId)->whereNull('opened_at')
                ->whereDate('starts_on', '<=', $today)->orderBy('starts_on')->first();

            if ($due !== null) {
                $due->update(['opened_at' => now()]);
                $this->prepareReadingCycle($due);
            }
        });
        unset($this->loaded[$branchId]);
    }

    /**
     * Close the branch's open period by hand: from now on nothing new is
     * recorded in it and payments wait for the next period to open (the one
     * set up ahead opens at once if its first day has come). The branch's
     * box period ends with it, closing the boxes still open in it. Only its
     * branch's manager may (BranchClosingAccess::manage(), checked by the
     * caller). Its lines and figures stay as they are.
     */
    public function close(BillingPeriod $period, User $actor): BillingPeriod
    {
        DB::transaction(function () use ($period): void {
            Branch::withoutGlobalScopes()->whereKey($period->branch_id)->lockForUpdate()->firstOrFail();
            $current = BillingPeriod::withoutGlobalScopes()->lockForUpdate()->findOrFail($period->id);
            if (! $current->isOpen()) {
                throw ValidationException::withMessages(['period' => 'تُغلق الفترة المفتوحة فقط؛ هذه الفترة '.$current->statusLabel().'.']);
            }
            $current->update(['closed_at' => now()]);
        });
        unset($this->loaded[$period->branch_id], $this->refreshed[$period->branch_id]);
        // The boxes follow the period: their period ends with it, and its open boxes close with it.
        app(BranchClosingWorkflow::class)->closeWithBillingPeriod($period->branch_id, CarbonImmutable::instance($period->fresh()->closed_at));
        $this->refresh($period->branch_id);

        return $period->refresh();
    }

    /**
     * Why a payment (or clearing) cannot be taken in the branch now: it has
     * billing periods and none is open. Null when it can: a period is open,
     * however long ago it began, or the branch keeps no periods. Whether a
     * period is open is all that decides it, never a date or a weekday.
     */
    public function paymentLockReason(?int $branchId): ?string
    {
        // A branch whose first period has not begun yet takes payments as before it had periods.
        if ($branchId === null || ! $this->of($branchId)->contains(fn (BillingPeriod $period): bool => $period->opened_at !== null) || $this->open($branchId) !== null) {
            return null;
        }

        return 'لا توجد فترة مفتوحة في الفرع الآن؛ تُسجَّل الدفعات في الفترة المفتوحة فقط. يفتح مدير الفرع الفترة التالية من صفحة فترات الفوترة.';
    }

    /**
     * Set up the branch's next period, from `$startsOn` to `$endsOn`. It
     * starts after the latest period ends (periods never overlap) and only
     * one may wait ahead; starting today or earlier, it opens now unless
     * another is still open (then it opens once that one is closed).
     */
    public function create(Branch $branch, CarbonImmutable $startsOn, CarbonImmutable $endsOn, User $actor, ?string $notes = null): BillingPeriod
    {
        $period = DB::transaction(function () use ($branch, $startsOn, $endsOn, $actor, $notes): BillingPeriod {
            Branch::withoutGlobalScopes()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $latest = BillingPeriod::withoutGlobalScopes()->where('branch_id', $branch->id)->orderByDesc('starts_on')->first();
            $fail = fn (string $message) => throw ValidationException::withMessages(['starts_on' => $message]);

            if ($endsOn->lt($startsOn)) {
                $fail('تاريخ نهاية الفترة يجب أن يكون بعد تاريخ بدايتها أو مساويًا له.');
            }
            if ($latest !== null && $latest->opened_at === null) {
                $fail('للفرع فترة مجدولة لم تبدأ بعد ('.$latest->label().'). عدّلها أو انتظر بدايتها.');
            }
            if ($latest !== null && $startsOn->toDateString() <= $latest->ends_on->toDateString()) {
                $fail('الفترة الجديدة تبدأ بعد نهاية الفترة السابقة ('.$latest->ends_on->format('j/n/Y').').');
            }

            return BillingPeriod::query()->create([
                'branch_id' => $branch->id, 'starts_on' => $startsOn->toDateString(), 'ends_on' => $endsOn->toDateString(),
                'created_by' => $actor->id, 'notes' => $notes,
            ]);
        });
        unset($this->loaded[$branch->id], $this->refreshed[$branch->id]);
        $this->refresh($branch->id);

        return $period->refresh();
    }

    /**
     * Change the dates of a period that has not started yet.
     */
    public function reschedule(BillingPeriod $period, CarbonImmutable $startsOn, CarbonImmutable $endsOn): BillingPeriod
    {
        DB::transaction(function () use ($period, $startsOn, $endsOn): void {
            Branch::withoutGlobalScopes()->whereKey($period->branch_id)->lockForUpdate()->firstOrFail();
            $current = BillingPeriod::withoutGlobalScopes()->lockForUpdate()->findOrFail($period->id);
            $previous = BillingPeriod::withoutGlobalScopes()->where('branch_id', $period->branch_id)->whereKeyNot($period->id)->orderByDesc('starts_on')->first();
            $fail = fn (string $message) => throw ValidationException::withMessages(['starts_on' => $message]);

            if ($current->opened_at !== null) {
                $fail('بدأت هذه الفترة؛ لا يمكن تغيير تاريخيها.');
            }
            if ($endsOn->lt($startsOn)) {
                $fail('تاريخ نهاية الفترة يجب أن يكون بعد تاريخ بدايتها أو مساويًا له.');
            }
            if ($previous !== null && $startsOn->toDateString() <= $previous->ends_on->toDateString()) {
                $fail('الفترة تبدأ بعد نهاية الفترة السابقة ('.$previous->ends_on->format('j/n/Y').').');
            }
            $current->update(['starts_on' => $startsOn->toDateString(), 'ends_on' => $endsOn->toDateString()]);
        });
        unset($this->loaded[$period->branch_id], $this->refreshed[$period->branch_id]);
        $this->refresh($period->branch_id);

        return $period->refresh();
    }

    /**
     * Approve a closed period for good: each of its branch's subscriptions'
     * figures are kept as they are now, and nothing in it changes again.
     */
    public function approve(BillingPeriod $period, User $actor): BillingPeriod
    {
        DB::transaction(function () use ($period, $actor): void {
            Branch::withoutGlobalScopes()->whereKey($period->branch_id)->lockForUpdate()->firstOrFail();
            $current = BillingPeriod::withoutGlobalScopes()->lockForUpdate()->findOrFail($period->id);
            if (! $current->isClosed()) {
                throw ValidationException::withMessages(['period' => $current->isApproved()
                    ? 'اعتُمدت هذه الفترة من قبل.'
                    : 'تُعتمد الفترة بعد إغلاقها.']);
            }

            $now = now();
            foreach (array_chunk(app(PeriodStatement::class)->rows($current)->values()->all(), 500) as $chunk) {
                SubscriberPeriodBalance::query()->insert(array_map(fn (array $row): array => [
                    'billing_period_id' => $current->id, 'subscription_id' => $row['subscriptionId'],
                    'opening' => Closing::money($row['opening']), 'invoice' => Closing::money($row['invoice']),
                    'discounts' => Closing::money($row['discounts']), 'charges' => Closing::money($row['charges']),
                    'payments' => Closing::money($row['payments']), 'closing' => Closing::money($row['remaining']),
                    'invoice_target' => Closing::money($row['invoiceTarget']), 'advance_used' => Closing::money($row['advanceUsed']),
                    'paid_to_invoice' => Closing::money($row['paidToInvoice']), 'discount_to_invoice' => Closing::money($row['discountToInvoice']),
                    'invoice_settled' => Closing::money($row['invoiceSettled']), 'paid_to_accumulated' => Closing::money($row['paidToAccumulated']),
                    'discount_to_accumulated' => Closing::money($row['discountToAccumulated']), 'new_advance' => Closing::money($row['newAdvance']),
                    'created_at' => $now, 'updated_at' => $now,
                ], $chunk));
            }
            $current->update(['approved_at' => $now, 'approved_by' => $actor->id]);
        });
        unset($this->loaded[$period->branch_id]);

        return $period->refresh();
    }

    /**
     * Whether `$actor` may still change what the period holds (record, edit
     * or remove its lines and readings): anyone while it is open, the
     * branch's manager while it is closed, no one once it is approved. The
     * system itself (no actor) may while it is not approved.
     */
    public function mayChange(BillingPeriod $period, ?User $actor): bool
    {
        return match ($period->status()) {
            BillingPeriod::STATUS_APPROVED => false,
            BillingPeriod::STATUS_CLOSED => $actor === null || BranchClosingAccess::manage($actor, $period->branch_id),
            default => true,
        };
    }

    /** Why the period may not be changed by `$actor`, or null when it may. */
    public function lockReason(?BillingPeriod $period, ?User $actor): ?string
    {
        if ($period === null || $this->mayChange($period, $actor)) {
            return null;
        }

        return $period->isApproved()
            ? 'الفترة '.$period->label().' معتمدة ومقفلة؛ لا يمكن تغيير حركاتها أو قراءاتها.'
            : 'الفترة '.$period->label().' مغلقة؛ يعدّلها مدير الفرع فقط حتى يعتمدها.';
    }

    /** Forget what was read, once periods change outside this service. */
    public function forget(?int $branchId = null): void
    {
        if ($branchId === null) {
            $this->loaded = $this->refreshed = [];
            $this->tableExists = null;

            return;
        }
        unset($this->loaded[$branchId], $this->refreshed[$branchId]);
    }

    /**
     * The period's reading cycle, read on its first day, with the branch's
     * standing distribution applied when it has one.
     */
    private function prepareReadingCycle(BillingPeriod $period): void
    {
        $cycle = ReadingCycle::withoutGlobalScopes()->where('branch_id', $period->branch_id)->whereDate('week_start', $period->starts_on->toDateString())->first();
        $createdBy = $period->created_by ?? ReadingDistribution::query()->where('branch_id', $period->branch_id)->value('approved_by');

        if ($cycle === null && $createdBy !== null) {
            $cycle = ReadingCycle::query()->create([
                'branch_id' => $period->branch_id, 'week_start' => $period->starts_on->toDateString(),
                'week_end' => $period->ends_on->toDateString(), 'reading_date' => $period->starts_on->toDateString(),
                'created_by' => $createdBy,
            ]);
        }

        if ($cycle !== null && ! $cycle->isClosed() && ReadingDistribution::query()->where('branch_id', $period->branch_id)->exists()) {
            $branch = Branch::withoutGlobalScopes()->findOrFail($period->branch_id);
            app(RecurringReadingDistribution::class)->prepare($branch, User::withoutGlobalScopes()->find($createdBy), false, $cycle);
        }
    }
}
