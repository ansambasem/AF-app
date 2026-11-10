<?php

namespace App\Models;

use App\Support\DeletionBlocker;
use Database\Factories\SubAreaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An area of a branch (shown as «المنطقة»; formerly «منطقة 2»): it
 * belongs to one branch, and holds that branch's meter boxes.
 */
#[Fillable(['name', 'branch_id'])]
class SubArea extends Model
{
    /** @use HasFactory<SubAreaFactory> */
    use HasFactory;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function meterBoxes(): HasMany
    {
        return $this->hasMany(MeterBox::class);
    }

    /**
     * Only the areas the given user works in: all of them for a Super
     * Admin, otherwise those of their own branch.
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->when(
            ! $user->isSuperAdmin(),
            fn (Builder $query) => $query->where('branch_id', $user->workBranchId()),
        );
    }

    /**
     * Why the sub-area can't be deleted yet — what still uses it — or null
     * when it can.
     */
    public function deletionBlocker(): ?string
    {
        return DeletionBlocker::describe('المنطقة', [
            'الطبلونات' => MeterBox::query()->where('sub_area_id', $this->id)->count(),
        ]);
    }
}
