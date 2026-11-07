<?php

namespace App\Models;

use App\Enums\TariffCategory;
use App\Support\DeletionBlocker;
use Database\Factories\TariffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['category', 'rate'])]
class Tariff extends Model
{
    /** @use HasFactory<TariffFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'category' => TariffCategory::class,
            'rate' => 'decimal:2',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Every kilo price it has had, newest first.
     */
    public function rateChanges(): HasMany
    {
        return $this->hasMany(TariffRateChange::class)->latest()->latest('id');
    }

    /**
     * Record its current kilo price in its history, when it is new or has
     * just changed.
     */
    public function recordRateChange(User $changedBy): void
    {
        if ($this->wasRecentlyCreated || $this->wasChanged('rate')) {
            $this->rateChanges()->create(['rate' => $this->rate, 'changed_by' => $changedBy->id]);
        }
    }

    /**
     * Why the tariff can't be deleted yet — what still uses it — or null
     * when it can.
     */
    public function deletionBlocker(): ?string
    {
        return DeletionBlocker::describe('التعرفة', [
            'المشتركون' => $this->subscriptions()->count(),
        ]);
    }
}
