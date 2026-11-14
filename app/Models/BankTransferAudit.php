<?php

namespace App\Models;

use Database\Factories\BankTransferAuditFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['cash_box_payment_id', 'audited_by', 'adjustment_transaction_id', 'currency', 'recorded_amount', 'bank_amount', 'exchange_rate', 'difference', 'outcome', 'reason', 'audited_at', 'posted_at'])]
class BankTransferAudit extends Model
{
    /** @use HasFactory<BankTransferAuditFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['recorded_amount' => 'decimal:2', 'bank_amount' => 'decimal:2', 'exchange_rate' => 'decimal:4', 'difference' => 'decimal:2', 'audited_at' => 'datetime', 'posted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $audit): void {
            if ($audit->getRawOriginal('posted_at') !== null || $audit->getRawOriginal('adjustment_transaction_id') !== null) {
                throw ValidationException::withMessages(['audit' => 'لا يمكن تغيير قرار تدقيق معتمد.']);
            }
        });
        static::deleting(fn (): never => throw ValidationException::withMessages(['audit' => 'لا يمكن حذف قرار تدقيق معتمد.']));
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(CashBoxPayment::class, 'cash_box_payment_id');
    }

    public function isPosted(): bool
    {
        return $this->posted_at !== null || $this->adjustment_transaction_id !== null;
    }

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'audited_by');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionTransaction::class, 'adjustment_transaction_id');
    }
}
