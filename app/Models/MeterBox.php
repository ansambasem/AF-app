<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Support\DeletionBlocker;
use Database\Factories\MeterBoxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'name_suffix', 'box_number', 'branch_id', 'sub_area_id', 'location'])]
class MeterBox extends Model
{
    /** @use HasFactory<MeterBoxFactory> */
    use BelongsToBranch, HasFactory;

    public function displayName(): string
    {
        return trim($this->name.' '.$this->name_suffix);
    }

    public function label(): string
    {
        return $this->displayName().' - ('.$this->box_number.')';
    }

    /**
     * Search the name, its suffix and the separate box number together.
     */
    #[Scope]
    protected function matchingLabel(Builder $query, string $search): Builder
    {
        foreach (preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) as $term) {
            $query->where(fn (Builder $matching) => $matching
                ->where('name', 'like', '%'.$term.'%')
                ->orWhere('name_suffix', 'like', '%'.$term.'%')
                ->orWhere('box_number', 'like', '%'.$term.'%'));
        }

        return $query;
    }

    public function subArea(): BelongsTo
    {
        return $this->belongsTo(SubArea::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Why the meter box can't be deleted yet — what still uses it — or null
     * when it can.
     */
    public function deletionBlocker(): ?string
    {
        return DeletionBlocker::describe('الطبلون', [
            'المشتركون' => $this->subscriptions()->count(),
            'توزيع القراءات' => ReadingAssignment::query()->where('meter_box_id', $this->id)->count(),
        ]);
    }
}
