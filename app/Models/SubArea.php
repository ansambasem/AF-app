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
use Illuminate\Support\Collection;

/**
 * An area of a branch (shown as «المنطقة»; stored as before, a sub-area of
 * the branch's area container): its branch is the one whose `area_id` it
 * shares, and it holds the branch's meter boxes.
 */
#[Fillable(['name', 'area_id'])]
class SubArea extends Model
{
    /** @use HasFactory<SubAreaFactory> */
    use HasFactory;

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function meterBoxes(): HasMany
    {
        return $this->hasMany(MeterBox::class);
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
     * The branches each area container belongs to, by container: what ties
     * an area to its branch, read from the links the branches already have.
     *
     * @return Collection<int, list<int>>
     */
    public static function branchesByContainer(): Collection
    {
        return Branch::query()->withoutGlobalScopes()->whereNotNull('area_id')->get(['id', 'area_id'])
            ->groupBy('area_id')->map(fn (Collection $branches): array => $branches->pluck('id')->all());
    }

    /**
     * The areas `$user` may pick, each with the branches it belongs to, so a
     * form lists only the chosen branch's areas.
     *
     * @return Collection<int, array{id: int, name: string, area_id: ?int, branch_ids: list<int>}>
     */
    public static function optionsFor(User $user): Collection
    {
        $branches = self::branchesByContainer();

        return self::query()->visibleTo($user)->orderBy('name')->get(['id', 'name', 'area_id'])
            ->map(fn (self $area): array => ['id' => $area->id, 'name' => $area->name, 'area_id' => $area->area_id, 'branch_ids' => $branches[$area->area_id] ?? []])
            ->values();
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
