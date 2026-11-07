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

#[Fillable(['name', 'area_id'])]
class SubArea extends Model
{
    /** @use HasFactory<SubAreaFactory> */
    use HasFactory;

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * Only the sub-areas the given user works in: all of them for a Super
     * Admin, otherwise those inside their own branch's area.
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->when(
            ! $user->isSuperAdmin(),
            fn (Builder $query) => $query->where('area_id', $user->branchAreaId()),
        );
    }

    /**
     * Why the sub-area can't be deleted yet — what still uses it — or null
     * when it can.
     */
    public function deletionBlocker(): ?string
    {
        return DeletionBlocker::describe('منطقة 2', [
            'الطبلونات' => MeterBox::query()->where('sub_area_id', $this->id)->count(),
        ]);
    }
}
