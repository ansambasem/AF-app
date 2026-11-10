<?php

namespace App\Models;

use App\Support\DeletionBlocker;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable(['name', 'location', 'phone', 'is_active'])]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'domain_enabled' => 'boolean',
        ];
    }

    /** The branch's areas (formerly «منطقة 2»), each holding its meter boxes. */
    public function subAreas(): HasMany
    {
        return $this->hasMany(SubArea::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function meterBoxes(): HasMany
    {
        return $this->hasMany(MeterBox::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function meterReadings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    /**
     * Every line on the accounts of the branch's subscriptions.
     */
    public function transactions(): HasManyThrough
    {
        return $this->hasManyThrough(SubscriptionTransaction::class, Subscription::class);
    }

    /**
     * Why the branch can't be deleted yet — what still uses it — or null
     * when it can.
     */
    public function deletionBlocker(): ?string
    {
        return DeletionBlocker::describe('الفرع', [
            'المشتركون' => $this->subscriptions()->count(),
            'المستخدمون' => $this->users()->count(),
            'الطبلونات' => $this->meterBoxes()->count(),
            'المناطق' => $this->subAreas()->count(),
            'القراءات' => $this->meterReadings()->count(),
        ]);
    }
}
