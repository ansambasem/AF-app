<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One change applied to many subscriptions at once — a field set to one
 * value — with each subscription's value before and after (its items), so
 * it can be undone.
 */
#[Fillable(['field', 'value', 'description', 'branch_id', 'user_id', 'changed_count', 'undone_at', 'undone_by', 'restored_count'])]
class SubscriptionBulkChange extends Model
{
    /** The subscription fields a bulk change may set. */
    public const FIELDS = ['minimum_charge', 'status', 'meter_box_id'];

    protected function casts(): array
    {
        return [
            'undone_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionBulkChangeItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function undoneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isUndone(): bool
    {
        return $this->undone_at !== null;
    }

    /**
     * The changes the given user may see: all of them for a Super Admin,
     * otherwise those made in their own branch.
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->when(! $user->isSuperAdmin(), fn (Builder $query) => $query->where('branch_id', $user->workBranchId()));
    }
}
