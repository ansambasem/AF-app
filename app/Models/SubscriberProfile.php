<?php

namespace App\Models;

use App\Support\BranchDomainScope;
use Database\Factories\SubscriberProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['full_name', 'national_id', 'phone', 'address'])]
class SubscriberProfile extends Model
{
    /** @use HasFactory<SubscriberProfileFactory> */
    use HasFactory;

    public const PERSONAL_FIELDS = ['full_name', 'national_id', 'phone', 'address'];

    /**
     * Every new person gets the next subscriber number automatically; it is
     * never taken from user input. Their subscriptions keep their own
     * account numbers.
     *
     * Keep the subscription's searchable personal fields in sync with its
     * shared profile. Financial data and meter assignments stay separate.
     */
    protected static function booted(): void
    {
        static::creating(function (SubscriberProfile $profile): void {
            $profile->subscriber_number ??= static::nextSubscriberNumber();
        });

        static::saved(function (SubscriberProfile $profile): void {
            if ($profile->wasChanged(self::PERSONAL_FIELDS)) {
                $profile->subscriptions()->withoutGlobalScope(BranchDomainScope::class)->update($profile->only(self::PERSONAL_FIELDS));
            }
        });

        static::updated(function (SubscriberProfile $profile): void {
            if ($profile->wasChanged(self::PERSONAL_FIELDS)) {
                $profile->recordChange();
            }
        });
    }

    /** The number after the highest one given so far: 1, 2, 3… */
    public static function nextSubscriberNumber(): int
    {
        return (int) static::query()->orderByDesc('subscriber_number')->lockForUpdate()->value('subscriber_number') + 1;
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(SubscriberProfileChange::class)->latest('created_at')->latest('id');
    }

    /**
     * Remember the personal details that were just saved as changed, with
     * their old and new values and who changed them. The details belong to
     * the person, so any of their subscriptions, in any branch, may be the
     * one edited; the history keeps whose hand it was. (Called from the
     * `updated` hook, where the original values are still the old ones.)
     */
    private function recordChange(): void
    {
        $user = auth()->user();

        $this->changes()->create([
            'user_id' => $user?->id,
            'branch_id' => $user?->workBranchId(),
            'changes' => collect(self::PERSONAL_FIELDS)
                ->filter(fn (string $field): bool => $this->wasChanged($field))
                ->mapWithKeys(fn (string $field): array => [$field => ['from' => $this->getOriginal($field), 'to' => $this->getAttribute($field)]])
                ->all(),
        ]);
    }
}
