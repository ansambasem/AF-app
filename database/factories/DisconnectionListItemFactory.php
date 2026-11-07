<?php

namespace Database\Factories;

use App\Enums\DisconnectionItemStatus;
use App\Enums\DisconnectionReason;
use App\Models\DisconnectionList;
use App\Models\DisconnectionListItem;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A subscription waiting on a disconnection list. The subscription should be
 * of the list's branch; give both when it matters.
 *
 * @extends Factory<DisconnectionListItem>
 */
class DisconnectionListItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'disconnection_list_id' => DisconnectionList::factory(),
            'subscription_id' => fn (array $attributes) => Subscription::factory()->create([
                'branch_id' => DisconnectionList::query()->findOrFail($attributes['disconnection_list_id'])->branch_id,
            ])->id,
            'branch_id' => fn (array $attributes) => Subscription::query()->findOrFail($attributes['subscription_id'])->branch_id,
            'active_subscription_id' => fn (array $attributes) => $attributes['subscription_id'],
            'reason' => DisconnectionReason::LatePayment,
            'scheduled_for' => now(config('app.business_timezone'))->addDays(3)->toDateString(),
            'status' => DisconnectionItemStatus::Scheduled,
            'balance_when_added' => 0,
            'added_by' => User::factory(),
        ];
    }
}
