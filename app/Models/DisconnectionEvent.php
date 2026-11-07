<?php

namespace App\Models;

use App\Enums\DisconnectionEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One step in the history of a subscription on a disconnection list: who
 * did what, when, and the values before and after. The history is only ever
 * added to.
 */
#[Fillable(['disconnection_list_item_id', 'type', 'old_values', 'new_values', 'reason', 'subscription_message_id', 'user_id'])]
class DisconnectionEvent extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A disconnection list event is never changed.'));
        static::deleting(fn () => throw new LogicException('A disconnection list event is never deleted.'));
    }

    protected function casts(): array
    {
        return [
            'type' => DisconnectionEventType::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(DisconnectionListItem::class, 'disconnection_list_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(SubscriptionMessage::class, 'subscription_message_id');
    }
}
