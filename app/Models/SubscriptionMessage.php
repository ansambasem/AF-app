<?php

namespace App\Models;

use App\Enums\MessageStatus;
use App\Models\Concerns\BelongsToBranch;
use Database\Factories\SubscriptionMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message as one subscription got it: the number it went to and the text
 * with their own details filled in.
 */
#[Fillable(['message_batch_id', 'subscription_id', 'branch_id', 'phone', 'body', 'status', 'error', 'sent_at', 'sent_by'])]
class SubscriptionMessage extends Model
{
    /** @use HasFactory<SubscriptionMessageFactory> */
    use BelongsToBranch, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => MessageStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MessageBatch::class, 'message_batch_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * Record that the message went out — by the SMS gateway, or by the
     * given staff member from their WhatsApp.
     */
    public function markSent(?User $sender = null): void
    {
        $this->update([
            'status' => MessageStatus::Sent,
            'error' => null,
            'sent_at' => now(),
            'sent_by' => $sender?->id,
        ]);
    }

    /**
     * Record that the SMS gateway turned the message down, and why.
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'status' => MessageStatus::Failed,
            'error' => mb_substr($error, 0, 255),
        ]);
    }
}
