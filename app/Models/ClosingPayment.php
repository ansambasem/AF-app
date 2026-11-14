<?php

namespace App\Models;

use App\Enums\ClosingMatchStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment as it sits in a daily closing. The payment keeps its own ID,
 * subscription and voucher; this only says which closing it belongs to and,
 * for a transfer, whether it was found in the receiving account.
 */
#[Fillable(['closing_id', 'subscription_transaction_id', 'match_status', 'matched_by', 'matched_at'])]
class ClosingPayment extends Model
{
    protected function casts(): array
    {
        return [
            'match_status' => ClosingMatchStatus::class,
            'matched_at' => 'datetime',
        ];
    }

    public function closing(): BelongsTo
    {
        return $this->belongsTo(Closing::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionTransaction::class, 'subscription_transaction_id');
    }

    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}
