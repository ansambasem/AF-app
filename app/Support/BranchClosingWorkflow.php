<?php

namespace App\Support;

use App\Enums\CashBoxType;
use App\Enums\ClosingStatus;
use App\Enums\PaymentMethod;
use App\Models\AuditNote;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashBoxPayment;
use App\Models\Closing;
use App\Models\ClosingSetting;
use App\Models\ClosingSettingChange;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Notifications\BranchClosingNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Branch sessions and packages are separate from historical company closings. */
class BranchClosingWorkflow
{
    public const CURRENCIES = ['ILS', 'USD', 'JOD'];

    public const ENTRY_DETAILS = [
        'transaction:id,subscription_id,reference_number,bank_name,sender_bank_name,voucher_number,manual_voucher_number,notes,status',
        'transaction.subscription:id,account_number,full_name,subscription_name',
        'box:id,user_id,closing_id,number,type',
        'box.user:id,name',
    ];

    public const BOX_DETAILS = [
        'user:id,name,role,is_active,branch_id',
        'user.permissions',
        'payments.transaction:id,subscription_id,type,reference_number,bank_name,sender_bank_name,sender_name,voucher_number,manual_voucher_number,notes,status,currency,currency_amount,exchange_rate,amount',
        'payments.transaction.subscription:id,account_number,full_name,subscription_name',
    ];

    public function locked(int $branchId, callable $operation): mixed
    {
        return DB::transaction(function () use ($branchId, $operation): mixed {
            Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();

            return $operation();
        }, 3);
    }

    /** Automatic startup includes the collection that triggered it, even when processing crosses a second. */
    public function start(int $branchId, User $actor, ?CarbonImmutable $startedAt = null): void
    {
        $this->locked($branchId, function () use ($branchId, $actor, $startedAt): void {
            $startedAt ??= CarbonImmutable::now()->utc();
            $setting = ClosingSetting::ownFor($branchId);
            $this->require(! $setting?->workflow_started_at, 'تم بدء إغلاق الفرع بالفعل.');
            $defaults = ClosingSetting::forBranch($branchId);
            $setting ??= new ClosingSetting(['branch_id' => $branchId]);
            $setting->fill(['week_starts_on' => $defaults->week_starts_on, 'cutoff_time' => $defaults->cutoff_time, 'auto_open' => true, 'frequency' => 'weekly', 'arrangement' => 'combined', 'workflow_started_at' => $startedAt, 'updated_by' => $actor->id]);
            $setting->save();
            $settings = $this->settings($setting);
            $end = $this->nextEnd($startedAt, $settings);
            $start = $end->setTimezone(config('app.business_timezone'))->subWeek()->utc();
            $this->createPeriod($branchId, $start, $end, $settings);
            $this->refresh($branchId);
        });
    }

    /** @return array{frequency: string, arrangement: string, week_starts_on: int, cutoff_time: string} */
    public function settings(ClosingSetting $setting): array
    {
        return ['frequency' => 'weekly', 'arrangement' => 'combined', 'week_starts_on' => $setting->week_starts_on, 'cutoff_time' => ClosingSetting::DEFAULT_CUTOFF];
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    private function weekly(array $settings): array
    {
        unset($settings['continuation']);

        return [...$settings, 'frequency' => 'weekly', 'arrangement' => 'combined', 'cutoff_time' => ClosingSetting::DEFAULT_CUTOFF];
    }

    /** @param array<string, mixed> $settings */
    public function nextEnd(CarbonImmutable $from, array $settings): CarbonImmutable
    {
        $local = $from->setTimezone(config('app.business_timezone'));
        $end = $local->setTimeFromTimeString($settings['cutoff_time']);
        $end = $end->addDays(((int) $settings['week_starts_on'] - $end->dayOfWeek + 7) % 7);
        if ($end->lessThanOrEqualTo($local)) {
            $end = $end->addWeek();
        }

        return $end->utc();
    }

    /** @param array<string, mixed> $settings */
    private function createPeriod(int $branchId, CarbonImmutable $from, CarbonImmutable $until, array $settings): Closing
    {
        $settings = $this->weekly($settings);

        return Closing::query()->firstOrCreate(['branch_id' => $branchId, 'coverage_from' => $from, 'book' => 'combined'], [
            'number' => 'BR-'.Str::ulid(), 'type' => 'weekly', 'period_start' => $from->setTimezone(config('app.business_timezone'))->toDateString(), 'period_end' => $until->subSecond()->setTimezone(config('app.business_timezone'))->toDateString(),
            'coverage_until' => $until, 'scheduled_until' => $until, 'status' => ClosingStatus::Draft, 'workflow_settings' => $settings,
        ]);
    }

    public function current(int $branchId, ?string $book = null): Closing
    {
        return Closing::query()->where('branch_id', $branchId)->whereNotNull('workflow_settings')
            ->where('coverage_from', '<=', now())->where('coverage_until', '>', now())
            ->when($book, fn ($query) => $query->whereIn('book', [$book, 'combined']))
            ->orderByDesc('coverage_from')->orderBy('id')->firstOrFail();
    }

    public function refresh(int $branchId): void
    {
        $this->locked($branchId, function () use ($branchId): void {
            $setting = ClosingSetting::ownFor($branchId);
            if (! $setting?->workflow_started_at) {
                return;
            }
            $latest = Closing::query()->where('branch_id', $branchId)->whereNotNull('workflow_settings')->orderByDesc('coverage_until')->orderBy('id')->firstOrFail();
            if ((int) $latest->workflow_settings['week_starts_on'] !== $setting->week_starts_on || $latest->workflow_settings['cutoff_time'] !== ClosingSetting::DEFAULT_CUTOFF) {
                $settings = $this->settings($setting);
                $until = $this->nextEnd($latest->coverage_from, $settings);
                $latest->update([
                    'workflow_settings' => $settings, 'coverage_until' => $until, 'scheduled_until' => $until,
                    'period_end' => $until->subSecond()->setTimezone(config('app.business_timezone'))->toDateString(),
                ]);
            }
            while ($latest->coverage_until->lessThanOrEqualTo(now())) {
                $change = ClosingSettingChange::query()->where('branch_id', $branchId)->whereNull('applied_at')->where('effective_from', '<=', $latest->coverage_until)->latest('id')->first();
                $settings = $this->settings($setting);
                if ($change) {
                    $settings = $this->weekly($change->settings);
                    $setting->update([...$settings, 'updated_by' => $change->user_id]);
                    ClosingSettingChange::query()->where('branch_id', $branchId)->whereNull('applied_at')->where('effective_from', '<=', $latest->coverage_until)->update(['applied_at' => now()]);
                }
                $until = $latest->scheduled_until->greaterThan($latest->coverage_until) ? $latest->scheduled_until : $this->nextEnd($latest->coverage_until, $settings);
                $latest = $this->createPeriod($branchId, $latest->coverage_until, $until, $settings);
            }
            $this->closeExpiredBoxes($branchId);
            SubscriptionTransaction::query()->where('branch_id', $branchId)->whereIn('type', [SubscriptionTransaction::TYPE_PAYMENT, SubscriptionTransaction::TYPE_CLEARING])
                ->where('status', SubscriptionTransaction::STATUS_ACTIVE)->where('created_at', '>=', $setting->workflow_started_at)
                ->whereNotIn('id', CashBoxPayment::query()->select('subscription_transaction_id'))->orderBy('id')
                ->chunkById(200, function ($payments): void {
                    foreach ($payments as $payment) {
                        $this->attachEntry($payment);
                    }
                });
        });
    }

    public function capture(SubscriptionTransaction $payment): void
    {
        if (! in_array($payment->type, [SubscriptionTransaction::TYPE_PAYMENT, SubscriptionTransaction::TYPE_CLEARING], true) || $payment->status !== SubscriptionTransaction::STATUS_ACTIVE) {
            return;
        }
        $this->locked($payment->branch_id, function () use ($payment): void {
            app(BranchSettings::class)->forget(ClosingSetting::class);
            if (! ClosingSetting::ownFor($payment->branch_id)?->workflow_started_at) {
                $this->start($payment->branch_id, $payment->recordedBy()->firstOrFail(), $payment->created_at->toImmutable());

                return;
            }
            $this->refresh($payment->branch_id);
        });
    }

    private function openBox(Closing $closing, int $userId, CashBoxType $type): CashBox
    {
        $key = $closing->branch_id.':'.$userId.':'.$type->value;

        return CashBox::query()->where('open_key', $key)->first()
            ?? $this->newBox(['open_key' => $key, 'branch_id' => $closing->branch_id, 'user_id' => $userId, 'type' => $type, 'closing_id' => $closing->id, 'opened_at' => now(), 'status' => 'open']);
    }

    /**
     * Create a box numbered by its id: short, so people can read, print and
     * quote it, and never reused.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function newBox(array $attributes): CashBox
    {
        $box = CashBox::query()->create(['number' => 'BOX-'.Str::ulid(), ...$attributes]);
        $box->update(['number' => (string) $box->id]);

        return $box;
    }

    private function entryMethod(SubscriptionTransaction $transaction): string
    {
        return $transaction->isClearing() ? 'clearing' : $transaction->payment_method->value;
    }

    /**
     * What an entry holds: always shekels, the company's only currency. A
     * payment in dollars or dinars counts at its value in shekels, at the
     * rate it was recorded at; the transaction keeps what was paid.
     *
     * @return array{currency: string, amount: string}
     */
    private function entryAmount(SubscriptionTransaction $payment): array
    {
        return ['currency' => 'ILS', 'amount' => Closing::money(abs(Closing::cents($payment->amount)))];
    }

    private function attachEntry(SubscriptionTransaction $payment, ?CarbonImmutable $originalTime = null): void
    {
        $type = CashBoxType::for($payment);
        $occurred = ($originalTime ?? $payment->created_at->toImmutable())->utc();
        $closing = Closing::query()->where('branch_id', $payment->branch_id)->whereNotNull('workflow_settings')->where('coverage_from', '<=', $occurred)->where('coverage_until', '>', $occurred)->whereIn('book', [$type->book(), 'combined'])->orderBy('id')->first();
        $late = $closing !== null && ($closing->submitted_snapshot !== null || $closing->coverage_until->lessThanOrEqualTo(now()));
        if (! $closing || $late) {
            $closing = $this->current($payment->branch_id);
        } elseif ($closing->book === 'bank') {
            $closing = Closing::query()->where('branch_id', $payment->branch_id)->where('coverage_from', $closing->coverage_from)->where('book', 'cash')->firstOrFail();
        }
        // Late syncs join the current period without reopening a completed week.
        $box = $this->openBox($closing, $payment->recorded_by, $type);
        $box->payments()->create(['subscription_transaction_id' => $payment->id, ...$this->entryAmount($payment), 'method' => $this->entryMethod($payment), 'status' => $type === CashBoxType::Cash ? 'cash' : 'pending', 'occurred_at' => $occurred, 'synced_at' => now(), 'is_late' => $late]);
    }

    public function syncOriginalTime(SubscriptionTransaction $payment, CarbonImmutable $occurred): void
    {
        if (! ClosingSetting::ownFor($payment->branch_id)?->workflow_started_at) {
            return;
        }
        $this->locked($payment->branch_id, function () use ($payment, $occurred): void {
            $entry = CashBoxPayment::query()->where('subscription_transaction_id', $payment->id)->first();
            $this->require($entry && $entry->box->status === 'open' && $entry->status !== 'confirmed', 'لا يمكن إعادة توزيع دفعة بعد إغلاق صندوقها أو تأكيدها.');
            $entry->delete();
            $this->attachEntry($payment, $occurred);
        });
    }

    public function syncEditablePayment(SubscriptionTransaction $payment): void
    {
        if (! ClosingSetting::ownFor($payment->branch_id)?->workflow_started_at) {
            return;
        }
        $entry = CashBoxPayment::query()->where('subscription_transaction_id', $payment->id)->first();
        $box = $entry?->box;
        if (! $box || ! $box->isEditable()) {
            return;
        }
        if (CashBoxType::for($payment) !== $box->type) {
            $occurred = $entry->occurred_at;
            $entry->delete();
            $this->attachEntry($payment, $occurred);
            $this->recountClosed($box);

            return;
        }
        $methodChanged = $entry->method !== $this->entryMethod($payment);
        $entry->update([
            ...$this->entryAmount($payment),
            'method' => $this->entryMethod($payment),
            ...($methodChanged ? ['status' => $payment->payment_method === PaymentMethod::Cash ? 'cash' : 'pending', 'confirmed_by' => null, 'confirmed_at' => null, 'confirmed_in_closing_id' => null] : []),
        ]);
        $this->recountClosed($box);
    }

    /** Keep a corrected share in its transfer's box while attributing the correction to its actual recorder. */
    public function syncCorrectedSplitPayment(SubscriptionTransaction $original, SubscriptionTransaction $replacement): void
    {
        if (! $original->isPayment() || ! $replacement->isPayment() || $original->split_payment_id === null
            || $original->split_payment_id !== $replacement->split_payment_id) {
            return;
        }
        $originalEntry = $original->boxEntry()->with('box')->first();
        $replacementEntry = $replacement->boxEntry()->with('box')->first();
        if ($originalEntry === null || $replacementEntry === null || $originalEntry->cash_box_id === $replacementEntry->cash_box_id) {
            return;
        }
        $this->require($originalEntry->box->isEditable() && $replacementEntry->box->isEditable()
            && $originalEntry->box->branch_id === $replacementEntry->box->branch_id
            && $originalEntry->box->type === $replacementEntry->box->type, 'لا يمكن نقل حصة الحوالة المصححة خارج صندوق الحوالة الأصلي.');
        $replacementEntry->update([
            'cash_box_id' => $originalEntry->cash_box_id,
            'occurred_at' => $originalEntry->occurred_at,
            'is_late' => $originalEntry->is_late,
        ]);
        $this->recountClosed($originalEntry->box);
        $this->recountClosed($replacementEntry->box);
    }

    public function releaseEditablePayment(SubscriptionTransaction $payment): void
    {
        $entry = CashBoxPayment::query()->where('subscription_transaction_id', $payment->id)->first();
        if ($entry) {
            $box = $entry->box;
            $this->require($box->isEditable(), 'لا يمكن حذف دفعة مرتبطة بصندوق مغلق.');
            $entry->delete();
            $this->recountClosed($box);
        }
    }

    /**
     * Keep legacy boxes that have not been confirmed closed in step with
     * their collector's corrections.
     */
    private function recountClosed(CashBox $box): void
    {
        if ($box->status === 'pending') {
            $box->update(['expected_counts' => $this->expected($box)]);
        }
    }

    /** @return array<string, string> */
    public function expected(CashBox $box): array
    {
        $payments = $box->payments()->where('method', 'cash')->whereHas('transaction', fn ($query) => $query->where('status', SubscriptionTransaction::STATUS_ACTIVE))->get();

        return collect(self::CURRENCIES)->mapWithKeys(fn (string $currency): array => [$currency => Closing::money($payments->where('currency', $currency)->sum(fn ($payment): int => Closing::cents($payment->amount)))])->all();
    }

    /**
     * Seal expired periods without attributing receipt or audit approval to
     * a person. Empty boxes are discarded as before; entries stay in place.
     */
    private function closeExpiredBoxes(int $branchId): void
    {
        CashBox::query()->where('branch_id', $branchId)->whereIn('status', ['open', 'pending'])
            ->whereHas('closing', fn ($query) => $query->whereNotNull('workflow_settings')->where('coverage_until', '<=', now()))
            ->with('closing')->withExists('payments')->withMax('payments', 'occurred_at')->chunkById(100, function ($boxes): void {
                foreach ($boxes as $box) {
                    if (! $box->payments_exists) {
                        $box->delete();

                        continue;
                    }
                    $lastEntryAt = CarbonImmutable::parse($box->payments_max_occurred_at, 'UTC');
                    if ($lastEntryAt->greaterThanOrEqualTo($box->closing->coverage_until)) {
                        $period = Closing::query()->where('branch_id', $box->branch_id)->whereNotNull('workflow_settings')
                            ->where('coverage_from', '<=', $lastEntryAt)->where('coverage_until', '>', $lastEntryAt)->orderBy('id')->firstOrFail();
                        $box->update(['closing_id' => $period->id]);
                        $box->setRelation('closing', $period);
                        if ($period->coverage_until->greaterThan(now())) {
                            continue;
                        }
                    }
                    $box->update([
                        'status' => 'received', 'closed_at' => $box->closing->coverage_until,
                        'close_reason' => CashBox::CLOSE_REASON_PERIOD_END, 'open_key' => null,
                        'expected_counts' => $this->expected($box),
                        'reason' => trim(($box->reason ? $box->reason."\n" : '').'أُغلق تلقائيًا لانتهاء الفترة الأسبوعية.'),
                    ]);
                }
            });
    }

    /**
     * The branch confirms a collector's box closed, in one step: it looked
     * over the entries, had the collector correct any mistake in them
     * first, and closes the box for audit review. Every type of box
     * closes the same way, cash as transfers: nothing is counted, a box
     * holds what its entries say, and the audit rejects what it finds
     * wrong. Once confirmed, the box's entries can no longer be corrected.
     */
    public function receive(CashBox $box, User $actor): void
    {
        $this->locked($box->branch_id, function () use ($box, $actor): void {
            $current = CashBox::query()->lockForUpdate()->findOrFail($box->id);
            $this->require($current->isEditable() && $this->mayReceive($current, $actor), 'لا يمكنك تأكيد إغلاق هذا الصندوق.');
            $this->accept($current, $actor);
            $box->refresh();
        });
    }

    /**
     * Confirms a box closed for the branch: closes it if it is still open,
     * takes its transfers and clearings as confirmed, and settles it in the
     * earliest week not yet sent from its own week on.
     */
    private function accept(CashBox $box, User $actor): void
    {
        $this->require($box->payments()->exists(), 'لا يمكن إغلاق صندوق فارغ.');
        $settling = $this->settlingClosing($box);
        $box->payments()->where('status', 'pending')->whereHas('transaction', fn ($query) => $query->where('status', SubscriptionTransaction::STATUS_ACTIVE))
            ->update(['status' => 'confirmed', 'confirmed_by' => $actor->id, 'confirmed_at' => now(), 'confirmed_in_closing_id' => $settling->id]);
        $expected = $this->expected($box);
        $box->update([
            'status' => 'received', 'closed_at' => now(), 'close_reason' => 'manual', 'open_key' => null,
            'received_by' => $actor->id, 'received_at' => now(), 'confirmed_at' => now(), 'received_in_closing_id' => $settling->id,
            'expected_counts' => $expected,
            ...($box->type->isCounted() ? ['head_counts' => $expected, 'decisions' => []] : []),
        ]);
        $box->user?->notify(new BranchClosingNotification('أكّد الفرع إغلاق صندوقك.', $box->number));
    }

    /**
     * The branch's open boxes that hold entries and that the actor may
     * confirm closed, oldest first: of one type when `$type` is given, and
     * one person's when `$userId` is.
     *
     * @return EloquentCollection<int, CashBox>
     */
    public function closableBoxes(int $branchId, User $actor, ?CashBoxType $type = null, ?int $userId = null): EloquentCollection
    {
        return CashBox::query()->where('branch_id', $branchId)->whereIn('status', ['open', 'pending'])
            ->when($type, fn ($query) => $query->where('type', $type))
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->whereHas('payments')->orderBy('id')->get()
            ->filter(fn (CashBox $box): bool => $this->mayReceive($box, $actor))->values();
    }

    /**
     * The branch confirms the open boxes of one type it picked closed at
     * once (`$boxIds`), each as `receive()` confirms a single box. Nothing
     * is closed when any of them is no longer open or not the actor's to
     * close.
     *
     * @param  list<int>  $boxIds
     */
    public function receiveType(int $branchId, User $actor, CashBoxType $type, array $boxIds): void
    {
        $boxes = $this->closableBoxes($branchId, $actor, $type)->whereIn('id', $boxIds);
        $this->require($boxIds !== [] && $boxes->count() === count(array_unique($boxIds)), 'بعض الصناديق المختارة لم تعد مفتوحة أو لا يمكنك إغلاقها.');
        foreach ($boxes as $box) {
            $this->receive($box, $actor);
        }
    }

    public function mayReceive(CashBox $box, User $actor): bool
    {
        return BranchClosingAccess::receive($actor, $box->branch_id)
            && ($box->user_id !== $actor->id || BranchClosingAccess::approve($actor, $box->branch_id));
    }

    /**
     * The week a box received now is kept under: the earliest week from the
     * box's own on that was not sent as a package (as weeks once were).
     */
    private function settlingClosing(CashBox $box): Closing
    {
        return Closing::query()->where('branch_id', $box->branch_id)->whereNotNull('workflow_settings')->whereNull('submitted_at')
            ->where('coverage_from', '>=', $box->closing->coverage_from)
            ->orderBy('coverage_from')->orderBy('id')->first() ?? $this->current($box->branch_id);
    }

    public function confirmBox(CashBox $box, User $actor): void
    {
        $this->require($box->type->isCounted() && $actor->id === $box->user_id && $box->status === 'received' && $box->close_reason !== CashBox::CLOSE_REASON_PERIOD_END && ! $box->confirmed_at, 'التأكيد متاح لصاحب الصندوق بعد الاستلام فقط.');
        $box->update(['confirmed_at' => now()]);
    }

    public function reviewEntry(CashBoxPayment $payment, User $actor, string $status): void
    {
        $this->locked($payment->box->branch_id, function () use ($payment, $actor, $status): void {
            $payment->refresh()->load('box');
            $this->require($payment->box->isEditable(), 'لا يمكن تغيير حركة ضمن صندوق مغلق.');
            $allowed = $payment->method === 'clearing' ? ['confirmed', 'rejected'] : ['confirmed', 'missing'];
            $this->require($payment->method !== 'cash' && in_array($status, $allowed, true) && $payment->status !== 'confirmed', 'لا يمكن تغيير دفعة مؤكدة.');
            $this->require($this->mayReceive($payment->box, $actor), 'لا يمكنك مراجعة حركات هذا الصندوق.');
            $this->require($payment->transaction->status === SubscriptionTransaction::STATUS_ACTIVE, 'الدفعة ملغاة أو مُرجعة.');
            $closing = $this->settlingClosing($payment->box);
            $payment->update(['status' => $status, 'confirmed_by' => $actor->id, 'confirmed_at' => $status === 'confirmed' ? now() : null, 'confirmed_in_closing_id' => $status === 'confirmed' ? $closing->id : null]);
            $box = $payment->box;
            if (in_array($status, ['missing', 'rejected'], true)) {
                foreach (User::query()->where('is_active', true)->get()->filter(fn (User $user): bool => BranchClosingAccess::manage($user, $box->branch_id)) as $admin) {
                    $admin->notify(new BranchClosingNotification('حركة مرفوضة تحتاج مراجعة الفرع.', $box->number, ['entry_id' => $payment->id, 'status' => $status]));
                }
            }
        });
    }

    /**
     * The collector's answer to an audit note the branch sent them; it goes
     * back to the branch to pass on to the auditor.
     */
    public function collectorReply(AuditNote $note, User $actor, string $body): void
    {
        $this->require($note->assigned_to === $actor->id && $note->status === 'with_collector', 'الملاحظة لا تنتظر ردّك.');
        $note->replies()->create(['user_id' => $actor->id, 'kind' => 'collector_response', 'body' => $body]);
        $note->update(['status' => 'collector_replied']);
    }

    /** @param array<string, mixed> $counts @param array<string, mixed> $expected @return array<string, string> */
    public function differences(array $counts, array $expected): array
    {
        return collect(self::CURRENCIES)->mapWithKeys(fn (string $currency): array => [$currency => Closing::money(Closing::cents($counts[$currency] ?? 0) - Closing::cents($expected[$currency] ?? 0))])->all();
    }

    public function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['action' => $message]);
        }
    }
}
