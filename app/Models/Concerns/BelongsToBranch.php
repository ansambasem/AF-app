<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Models\User;
use App\Support\BranchDomainContext;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For models that carry a `branch_id`: the branch relation, plus the
 * branch scoping every list uses — a Super Admin sees every branch,
 * everyone else only their own.
 */
trait BelongsToBranch
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Only the records the given user may see: all of them for a Super
     * Admin, otherwise those in the user's own branch.
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->when(
            ! $user->isSuperAdmin() || BranchDomainContext::id() !== null,
            fn (Builder $query) => $query->where($query->qualifyColumn('branch_id'), $user->workBranchId()),
        );
    }
}
