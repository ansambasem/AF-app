<?php

namespace App\Support;

use App\Enums\CashBoxType;
use App\Enums\Currency;
use App\Models\BankTransferAudit;
use App\Models\CashBoxPayment;
use App\Models\Closing;
use App\Models\SplitPayment;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class SplitTransferAuditing
{
    public function __construct(private BranchClosingWorkflow $workflow) {}

    /** @return Collection<int, SubscriptionTransaction> */
    private function parts(int $splitId, int $branchId, User $actor): Collection
    {
        SplitPayment::withoutGlobalScope(BranchDomainScope::class)->whereKey($splitId)->lockForUpdate()->firstOrFail();
        $parts = SubscriptionTransaction::withoutGlobalScope(BranchDomainScope::class)
            ->where('split_payment_id', $splitId)->where('type', SubscriptionTransaction::TYPE_PAYMENT)
            ->with([
                'subscription' => fn (BelongsTo $query) => $query->withoutGlobalScope(BranchDomainScope::class),
                'boxEntry' => fn (HasOne $query) => $query->withoutGlobalScope(BranchDomainScope::class),
                'boxEntry.box' => fn (BelongsTo $query) => $query->withoutGlobalScope(BranchDomainScope::class),
                'boxEntry.box.audit', 'boxEntry.audit',
            ])->orderBy('id')->lockForUpdate()->get();
        abort_if($parts->contains(fn (SubscriptionTransaction $part): bool => $part->subscription === null
            || $part->subscription->branch_id !== $branchId || ! $actor->auditIncludesBranch($part->subscription->branch_id)
            || (BranchDomainContext::id() !== null && $part->subscription->branch_id !== BranchDomainContext::id())), 404);
        $this->workflow->require(! $parts->contains(fn (SubscriptionTransaction $part): bool => $part->isCancelled() && ! $parts->contains('corrects_id', $part->id)), 'تحتوي الحوالة على جزء مُلغى أو مُرجع؛ راجع الحوالة كاملة قبل اعتمادها.');
        $active = $parts->reject(fn (SubscriptionTransaction $part): bool => $part->isCancelled())->values();
        $this->workflow->require($active->isNotEmpty(), 'لا توجد حصص فعالة لهذه الحوالة.');
        $this->workflow->require(! $active->contains(fn (SubscriptionTransaction $part): bool => $part->currency !== Currency::Shekel
            || $part->payment_method?->value !== 'bank_transfer' || (float) $part->exchange_rate !== 1.0 || $part->boxEntry?->box?->status !== 'received'
            || $part->boxEntry->box->type !== CashBoxType::BankTransfer), 'يجب إغلاق جميع صناديق حصص الحوالة المقسّمة قبل تدقيقها أو اعتمادها.');

        return $active;
    }

    /** @param Collection<int, SubscriptionTransaction> $parts */
    private function revision(Collection $parts): string
    {
        return hash('sha256', json_encode($parts->map(fn (SubscriptionTransaction $part): array => [
            $part->only(['id', 'amount', 'reference_number', 'bank_name', 'sender_bank_name', 'sender_name', 'notes']),
            $part->boxEntry->box->only(['id', 'status']), $part->boxEntry->box->audit?->id,
            $part->boxEntry->audit?->only(['bank_amount', 'reason', 'audited_by', 'audited_at', 'posted_at', 'adjustment_transaction_id']),
        ])->all(), JSON_THROW_ON_ERROR));
    }

    /** @param Collection<int, SubscriptionTransaction> $parts */
    private function requireEditable(Collection $parts): void
    {
        $this->workflow->require(! $parts->contains(fn (SubscriptionTransaction $part): bool => $part->boxEntry->box->audit !== null
            || ($part->boxEntry->audit?->isPosted() ?? false)), 'اعتُمد أحد أجزاء الحوالة؛ لا يمكن تغيير مراجعتها المالية.');
    }

    /** @return array<string, mixed> */
    public function details(CashBoxPayment $entry, User $actor): array
    {
        return $this->workflow->locked($entry->box->branch_id, function () use ($entry, $actor): array {
            $parts = $this->parts($entry->transaction->split_payment_id, $entry->box->branch_id, $actor);
            $this->workflow->require($parts->contains('id', $entry->subscription_transaction_id), 'هذه الحصة ملغاة أو مُرجعة؛ حدّث الصفحة.');
            $this->requireEditable($parts);

            return [
                'revision' => $this->revision($parts),
                'total' => Closing::money($parts->sum(fn (SubscriptionTransaction $part): int => abs(Closing::cents($part->amount)))),
                'bank_amount' => Closing::money($parts->sum(fn (SubscriptionTransaction $part): int => Closing::cents($part->boxEntry->audit?->bank_amount ?? abs((float) $part->amount)))),
                'reason' => $parts->first(fn (SubscriptionTransaction $part): bool => filled($part->boxEntry->audit?->reason))?->boxEntry->audit->reason ?? '',
                'parts' => $parts->map(fn (SubscriptionTransaction $part): array => [
                    'transaction_id' => $part->id, 'subscriptionName' => $part->subscription->displayName(),
                    'accountNumber' => $part->subscription->account_number, 'boxNumber' => $part->boxEntry->box->number,
                    'recorded' => Closing::money(abs(Closing::cents($part->amount))),
                    'bank_amount' => $part->boxEntry->audit?->bank_amount ?? Closing::money(abs(Closing::cents($part->amount))),
                ])->all(),
            ];
        });
    }

    /**
     * @param  list<array{transaction_id: int, bank_amount: string|int|float}>  $allocations
     * @param  Closure(CashBoxPayment, User, string, ?string): BankTransferAudit  $record
     */
    public function record(CashBoxPayment $entry, User $actor, string $total, ?string $reason, array $allocations, ?string $revision, Closure $record): BankTransferAudit
    {
        return $this->workflow->locked($entry->box->branch_id, function () use ($entry, $actor, $total, $reason, $allocations, $revision, $record): BankTransferAudit {
            $parts = $this->parts($entry->transaction->split_payment_id, $entry->box->branch_id, $actor);
            $this->workflow->require($parts->contains('id', $entry->subscription_transaction_id), 'هذه الحصة ملغاة أو مُرجعة؛ حدّث الصفحة.');
            $this->workflow->require(Closing::cents($total) >= 0 && Closing::cents($total) <= 100000000, 'أدخل مبلغًا صحيحًا وصل البنك.');
            $amounts = collect($allocations)->keyBy('transaction_id');
            $ids = array_map('intval', $amounts->keys()->all());
            sort($ids);
            if (count($allocations) !== $parts->count() || $ids !== $parts->pluck('id')->all()
                || $amounts->sum(fn (array $part): int => Closing::cents($part['bank_amount'])) !== Closing::cents($total)) {
                throw ValidationException::withMessages(['parts' => 'وزّع كامل المبلغ الواصل على جميع حصص الحوالة؛ يجب أن يساوي مجموع الحصص إجمالي الواصل.']);
            }
            $unchanged = $parts->every(fn (SubscriptionTransaction $part): bool => $part->boxEntry->audit !== null
                && Closing::cents($part->boxEntry->audit->bank_amount) === Closing::cents($amounts[$part->id]['bank_amount'])
                && (string) $part->boxEntry->audit->reason === trim((string) $reason));
            if ($unchanged) {
                return $parts->firstWhere('id', $entry->transaction->id)->boxEntry->audit;
            }
            $this->requireEditable($parts);
            if ($revision === null || ! hash_equals($this->revision($parts), $revision)) {
                throw ValidationException::withMessages(['revision' => 'تغيّرت الحوالة أو مراجعتها؛ أغلق النافذة وافتحها مجددًا قبل الحفظ.']);
            }
            $selected = null;
            foreach ($parts as $part) {
                $audit = $record($part->boxEntry, $actor, (string) $amounts[$part->id]['bank_amount'], $reason);
                if ($part->id === $entry->transaction->id) {
                    $selected = $audit;
                }
            }

            return $selected;
        });
    }

    /**
     * Post the complete reviewed transfer atomically, even across multiple closed boxes.
     *
     * @param  Closure(CashBoxPayment, BankTransferAudit, User): void  $post
     */
    public function complete(CashBoxPayment $entry, User $actor, Closure $post): void
    {
        $parts = $this->parts($entry->transaction->split_payment_id, $entry->box->branch_id, $actor);
        $reviewed = $parts->filter(fn (SubscriptionTransaction $part): bool => $part->boxEntry->audit !== null);
        $this->workflow->require($reviewed->isEmpty() || $reviewed->count() === $parts->count(), 'مراجعة الحوالة المقسّمة غير مكتملة؛ افتح تعديل الحوالة وراجع جميع حصصها قبل الاعتماد.');
        foreach ($parts as $part) {
            $audit = $part->boxEntry->audit ?? BankTransferAudit::query()->create([
                'cash_box_payment_id' => $part->boxEntry->id, 'audited_by' => $actor->id,
                'currency' => $part->currency->value, 'recorded_amount' => abs((float) $part->amount),
                'bank_amount' => abs((float) $part->amount), 'exchange_rate' => 1,
                'difference' => '0.00', 'outcome' => 'matched', 'audited_at' => now(),
            ]);
            $post($part->boxEntry, $audit, $actor);
        }
    }
}
