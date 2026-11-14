<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a closing's history; `user_id` is empty for the system.
 */
#[Fillable(['closing_id', 'user_id', 'action', 'description'])]
class ClosingEvent extends Model
{
    public const UPDATED_AT = null;

    public function closing(): BelongsTo
    {
        return $this->belongsTo(Closing::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
