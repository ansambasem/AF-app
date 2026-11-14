<?php

namespace App\Models;

use Database\Factories\DebtRepaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['collector_debt_id', 'closing_id', 'received_by', 'amount'])]
class DebtRepayment extends Model
{
    /** @use HasFactory<DebtRepaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(CollectorDebt::class, 'collector_debt_id');
    }

    public function closing(): BelongsTo
    {
        return $this->belongsTo(Closing::class);
    }
}
