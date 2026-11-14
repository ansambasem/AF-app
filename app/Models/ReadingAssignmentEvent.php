<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One change to a box's allocation: who moved it from where to where, and
 * when. The history is only ever added to.
 */
#[Fillable(['reading_assignment_id', 'action', 'from_channel', 'from_user_id', 'to_channel', 'to_user_id', 'version', 'reason', 'actor_id'])]
class ReadingAssignmentEvent extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A reading assignment event is never changed.'));
        static::deleting(fn () => throw new LogicException('A reading assignment event is never deleted.'));
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ReadingAssignment::class, 'reading_assignment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
