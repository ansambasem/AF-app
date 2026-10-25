<?php

namespace App\Models;

use App\Support\BranchDomainContext;
use App\Support\BranchDomainScope;
use Closure;
use Database\Factories\SplitPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One bank transfer divided between several subscriptions: what the payer
 * sent in one piece, and the payments — one per subscription — it became.
 */
#[Fillable(['reference_number', 'bank_name', 'sender_bank_name', 'sender_name', 'total_amount', 'notes', 'recorded_by', 'bank_received_on'])]
class SplitPayment extends Model
{
    /** @use HasFactory<SplitPaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'bank_received_on' => 'date:Y-m-d',
        ];
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * The payments it was divided into, the first one recorded first.
     * Cancelled, refunded or corrected ones stay listed.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionTransaction::class)->oldest()->orderBy('id');
    }

    /**
     * Lock every branch before the transfer and its parts, using the same branch
     * lock as payment recording and box closure. Inspect the complete transfer:
     * a domain scope must never silently turn a group mutation into a partial one.
     *
     * @param  Closure(Collection<int, SubscriptionTransaction>): mixed  $operation
     */
    public function withPaymentsLocked(User $actor, Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $operation): mixed {
            $partsQuery = fn (): Builder => SubscriptionTransaction::withoutGlobalScope(BranchDomainScope::class)
                ->where('split_payment_id', $this->id)->where('type', SubscriptionTransaction::TYPE_PAYMENT)
                ->with(['subscription' => fn (BelongsTo $subscription) => $subscription->withoutGlobalScope(BranchDomainScope::class)])
                ->orderBy('id');
            $parts = $partsQuery()->get();
            $this->requireAccessibleParts($actor, $parts);
            $branchIds = $parts->pluck('subscription.branch_id')->unique()->sort()->values()->all();
            Branch::withoutGlobalScope(BranchDomainScope::class)->whereIn('id', $branchIds)->orderBy('id')->lockForUpdate()->get();
            self::withoutGlobalScope(BranchDomainScope::class)->whereKey($this->id)->lockForUpdate()->firstOrFail();
            $this->refresh();
            $parts = $partsQuery()->lockForUpdate()->get();
            $this->requireAccessibleParts($actor, $parts);
            if ($parts->pluck('subscription.branch_id')->diff($branchIds)->isNotEmpty()) {
                throw ValidationException::withMessages(['action' => 'تغيّرت أجزاء الحوالة؛ حدّث الصفحة وأعد المحاولة.']);
            }

            return $operation($parts);
        }, 3);
    }

    /** @param Collection<int, SubscriptionTransaction> $parts */
    private function requireAccessibleParts(User $actor, Collection $parts): void
    {
        if ($parts->isEmpty() || $parts->contains(fn (SubscriptionTransaction $part): bool => $part->subscription === null
            || (! $actor->isSuperAdmin() && $part->subscription->branch_id !== $actor->workBranchId())
            || (BranchDomainContext::id() !== null && $part->subscription->branch_id !== BranchDomainContext::id()))) {
            throw ValidationException::withMessages(['action' => 'لا يمكن تنفيذ العملية دون صلاحية الوصول إلى جميع أجزاء الحوالة المقسّمة.']);
        }
    }

    /** @return array<string, mixed> */
    public function editingDetails(User $actor): array
    {
        return $this->withPaymentsLocked($actor, function (Collection $parts) use ($actor): array {
            $active = $this->editableParts($actor, $parts);

            return [
                'id' => $this->id,
                'bank_received_on' => $this->bank_received_on?->toDateString(),
                ...$this->only(['bank_name', 'sender_bank_name', 'sender_name', 'reference_number', 'notes', 'total_amount']),
                'revision' => $this->editingRevision($parts),
                'parts' => $active->map(fn (SubscriptionTransaction $part): array => [
                    'id' => $part->id, 'subscriptionName' => $part->subscription->displayName(),
                    'accountNumber' => $part->subscription->account_number, 'amount' => Closing::money(-Closing::cents($part->amount)),
                ])->values()->all(),
            ];
        });
    }

    /**
     * Amend shared information atomically without touching amounts or allocation.
     *
     * @param  array<string, mixed>  $data
     */
    public function revise(User $actor, array $data): void
    {
        $this->withPaymentsLocked($actor, function (Collection $parts) use ($actor, $data): void {
            $active = $this->editableParts($actor, $parts);
            if (isset($data['revision']) && ! hash_equals($this->editingRevision($parts), $data['revision'])) {
                throw ValidationException::withMessages(['action' => 'تغيّرت الحوالة منذ فتح النافذة؛ أغلقها وافتحها مجددًا لمراجعة أحدث البيانات.']);
            }
            $fields = array_intersect_key($data, array_flip(SubscriptionTransaction::AMENDABLE_FIELDS));
            $reference = trim($data['reference_number'] ?? $this->reference_number);
            $referenceChanged = $active->contains(fn (SubscriptionTransaction $part): bool => $part->reference_number !== $reference);
            if ($referenceChanged && SubscriptionTransaction::activeReferenceConflict($reference, null, $this->id) !== null) {
                throw ValidationException::withMessages(['reference_number' => 'هذا الرقم المرجعي مسجّل مسبقًا على دفعة أخرى.']);
            }
            $reason = trim($data['amendment_reason']);
            $changed = false;
            foreach ($active as $part) {
                $changes = [];
                foreach ($fields as $field => $value) {
                    $value = filled($value) ? trim((string) $value) : null;
                    $oldValue = $field === 'bank_received_on' ? $part->bank_received_on?->toDateString() : $part->$field;
                    if ((string) ($oldValue ?? '') !== (string) ($value ?? '')) {
                        $changes[$field] = [$oldValue, $value];
                    }
                }
                if ($changes !== []) {
                    $part->amend($actor, $fields, $reason);
                }
                $changed = $changed || $changes !== [];
            }
            if (! $changed) {
                throw ValidationException::withMessages(['details' => 'غيّر بيانًا واحدًا على الأقل قبل الحفظ.']);
            }
            $this->update($fields);
        });
    }

    /** @param Collection<int, SubscriptionTransaction> $parts */
    private function editableParts(User $actor, Collection $parts): Collection
    {
        $active = $parts->reject(fn (SubscriptionTransaction $part): bool => $part->isCancelled());
        if ($active->isEmpty() || $parts->contains(fn (SubscriptionTransaction $part): bool => $part->isCancelled() && ! $parts->contains('corrects_id', $part->id))
            || $active->contains(fn (SubscriptionTransaction $part): bool => ! $part->isAmendable(lockForUpdate: true) || ! $actor->can('amend', $part))) {
            throw ValidationException::withMessages(['action' => 'لا يمكن تعديل الحوالة كاملة: أحد أجزائها مغلق أو مُرجع أو غير مسموح بتعديله.']);
        }

        return $active;
    }

    /** @param Collection<int, SubscriptionTransaction> $parts */
    private function editingRevision(Collection $parts): string
    {
        return hash('sha256', json_encode([$this->only(['bank_name', 'sender_bank_name', 'sender_name', 'reference_number', 'notes', 'total_amount', 'bank_received_on']),
            $parts->map(fn (SubscriptionTransaction $part): array => $part->only(['id', 'amount', 'bank_name', 'sender_bank_name', 'sender_name', 'reference_number', 'notes', 'cancelled_at', 'bank_received_on']))->all(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Record the transfer and a payment on each subscription for its share,
     * all together or not at all. `$shared` is what the payments have in
     * common (the bank, the sender, the reference, the notes) and `$parts`
     * lists each subscription with its amount. A reference already on another
     * payment is refused unless the collector confirmed the duplicate; the
     * parts themselves share it, which is not a duplicate.
     *
     * @param  array{total_amount: float|string, bank_name: string, sender_bank_name?: ?string, sender_name: string, reference_number: string, notes?: ?string, confirm_duplicate_reference?: bool}  $shared
     * @param  array<int, array{subscription: Subscription, amount: float|string}>  $parts
     */
    public static function record(User $collector, array $shared, array $parts): self
    {
        return DB::transaction(function () use ($collector, $shared, $parts): self {
            $split = self::create([
                'reference_number' => trim($shared['reference_number']),
                'bank_name' => $shared['bank_name'],
                'bank_received_on' => $shared['bank_received_on'] ?? null,
                'sender_bank_name' => $shared['sender_bank_name'] ?? null,
                'sender_name' => trim($shared['sender_name']),
                'total_amount' => $shared['total_amount'],
                'notes' => $shared['notes'] ?? null,
                'recorded_by' => $collector->id,
            ]);

            foreach ($parts as $part) {
                SubscriptionTransaction::recordPayment($part['subscription'], $collector, [
                    'amount' => $part['amount'],
                    'currency' => 'ILS',
                    'payment_method' => 'bank_transfer',
                    'bank_name' => $split->bank_name,
                    'bank_received_on' => $split->bank_received_on?->toDateString(),
                    'sender_bank_name' => $split->sender_bank_name,
                    'sender_name' => $split->sender_name,
                    'reference_number' => $split->reference_number,
                    'notes' => $split->notes,
                    'split_payment_id' => $split->id,
                    // The parts share the reference on purpose; another payment holding it is still a duplicate.
                    'confirm_duplicate_reference' => $shared['confirm_duplicate_reference'] ?? false,
                ]);
            }

            return $split;
        });
    }

    /**
     * What a payment that is part of it shows next to its reference, for the
     * badge that opens the transfer's details.
     *
     * @return array{id: int, total: string}
     */
    public function badge(): array
    {
        return ['id' => $this->id, 'total' => SubscriptionTransaction::formatAmount($this->total_amount)];
    }

    /**
     * The sum of its payments still standing, in shekels: the transfer's
     * parts less any cancelled, refunded or corrected since.
     */
    public function standingTotal(): string
    {
        return number_format(
            $this->payments()->whereNull('cancelled_at')->get()->sum(fn (SubscriptionTransaction $payment): float => -(float) $payment->amount),
            2,
            '.',
            '',
        );
    }
}
