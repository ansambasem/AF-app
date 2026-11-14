<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One subscription's value before and after a bulk change, and the dates
 * the change moved with it (`side_effects`: field => old and new).
 */
#[Fillable(['subscription_bulk_change_id', 'subscription_id', 'old_value', 'new_value', 'side_effects'])]
class SubscriptionBulkChangeItem extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['side_effects' => 'array'];
    }

    public function change(): BelongsTo
    {
        return $this->belongsTo(SubscriptionBulkChange::class, 'subscription_bulk_change_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
