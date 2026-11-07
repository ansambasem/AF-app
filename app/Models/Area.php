<?php

namespace App\Models;

use App\Support\DeletionBlocker;
use Database\Factories\AreaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'governorate_id'])]
class Area extends Model
{
    /** @use HasFactory<AreaFactory> */
    use HasFactory;

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function subAreas(): HasMany
    {
        return $this->hasMany(SubArea::class);
    }

    /**
     * Only the areas the given user works in: all of them for a Super
     * Admin, otherwise just their own branch's area.
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->when(
            ! $user->isSuperAdmin(),
            fn (Builder $query) => $query->whereKey($user->branchAreaId()),
        );
    }

    /**
     * Why the area can't be deleted yet — what still uses it — or null
     * when it can.
     */
    public function deletionBlocker(): ?string
    {
        return DeletionBlocker::describe('المنطقة', [
            'منطقة 2' => $this->subAreas()->count(),
            'الفروع' => Branch::query()->where('area_id', $this->id)->count(),
        ]);
    }
}
