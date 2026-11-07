<?php

namespace App\Models;

use App\Enums\CashTransferMethod;
use App\Enums\CashTransferStatus;
use Database\Factories\CashTransferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash a branch hands over to the company after a daily closing. It is in
 * transit from the moment it leaves the branch until the recipient
 * confirms receiving it. It moves money inside the company; it is never a
 * new collection.
 */
#[Fillable([
    'closing_id', 'branch_id', 'amount', 'method', 'sent_by', 'recipient_id', 'sent_at', 'proof_path', 'notes',
    'status', 'received_by', 'received_at', 'currency',
])]
class CashTransfer extends Model
{
    /** @use HasFactory<CashTransferFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'method' => CashTransferMethod::class,
            'status' => CashTransferStatus::class,
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function closing(): BelongsTo
    {
        return $this->belongsTo(Closing::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
