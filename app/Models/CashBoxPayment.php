<?php

namespace App\Models;

use Database\Factories\CashBoxPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['cash_box_id', 'subscription_transaction_id', 'currency', 'amount', 'method', 'status', 'occurred_at', 'synced_at', 'is_late', 'confirmed_in_closing_id', 'confirmed_by', 'confirmed_at'])]
class CashBoxPayment extends Model
{
    /** @use HasFactory<CashBoxPaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'occurred_at' => 'immutable_datetime', 'synced_at' => 'datetime', 'confirmed_at' => 'datetime', 'is_late' => 'boolean'];
    }

    /** Open-box lists show active payments; closed boxes retain their historical entries. */
    #[Scope]
    protected function visibleInBox(Builder $query): void
    {
        $query->where(fn (Builder $entries) => $entries
            ->whereHas('transaction', fn (Builder $transaction) => $transaction->where('status', SubscriptionTransaction::STATUS_ACTIVE))
            ->orWhereHas('box', fn (Builder $box) => $box->whereNotIn('status', ['open', 'pending'])));
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(CashBox::class, 'cash_box_id');
    }

    public function audit(): HasOne
    {
        return $this->hasOne(BankTransferAudit::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(SubscriptionTransaction::class, 'subscription_transaction_id');
    }
}
