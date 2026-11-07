<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record left by permanently deleting account lines: who deleted
 * them, how (TransactionAction, or "erase" for the last line erased for
 * good), why, and each line as it was (`transactions`, its stored
 * attributes), for the audit log. The lines themselves are gone.
 */
#[Fillable(['subscription_id', 'branch_id', 'user_id', 'action', 'reason', 'transactions'])]
class TransactionDeletion extends Model
{
    public const UPDATED_AT = null;

    /** A final line erased for good (SubscriptionTransaction::erase). */
    public const ACTION_ERASE = 'erase';

    protected function casts(): array
    {
        return [
            'transactions' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Record the deletion of these lines (their stored attributes, the
     * line acted on first), all of one subscription.
     *
     * @param  array<int, array<string, mixed>>  $transactions
     */
    public static function record(User $actor, string $action, ?string $reason, array $transactions): self
    {
        return self::create([
            'subscription_id' => $transactions[0]['subscription_id'] ?? null,
            'branch_id' => $transactions[0]['branch_id'] ?? null,
            'user_id' => $actor->id,
            'action' => $action,
            'reason' => filled($reason) ? trim($reason) : null,
            'transactions' => array_values($transactions),
        ]);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
