<?php

namespace Database\Factories;

use App\Enums\MessageStatus;
use App\Models\MessageBatch;
use App\Models\Subscription;
use App\Models\SubscriptionMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubscriptionMessage> */
class SubscriptionMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'message_batch_id' => MessageBatch::factory(),
            'subscription_id' => Subscription::factory(),
            'branch_id' => fn (array $attributes) => Subscription::find($attributes['subscription_id'])->branch_id,
            'phone' => '0599'.fake()->numerify('######'),
            'body' => fake()->sentence(),
            'status' => MessageStatus::Pending,
        ];
    }

    /**
     * Turned down by the SMS gateway.
     */
    public function failed(): static
    {
        return $this->state(fn () => ['status' => MessageStatus::Failed, 'error' => 'Gateway error']);
    }
}
