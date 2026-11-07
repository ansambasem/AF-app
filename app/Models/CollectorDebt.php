<?php

namespace App\Models;

use Database\Factories\CollectorDebtFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['cash_box_id', 'user_id', 'branch_id', 'currency', 'amount', 'remaining'])]
class CollectorDebt extends Model
{
    /** @use HasFactory<CollectorDebtFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'remaining' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(CashBox::class, 'cash_box_id');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(DebtRepayment::class);
    }
}
