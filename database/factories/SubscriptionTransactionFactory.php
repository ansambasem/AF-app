<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionTransaction>
 */
class SubscriptionTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'recorded_by' => fn (array $attributes): int => Subscription::findOrFail($attributes['subscription_id'])->registered_by,
            'type' => 'subscription_fee',
            'payment_method' => fn (array $attributes): ?string => $attributes['type'] === SubscriptionTransaction::TYPE_PAYMENT ? 'cash' : null,
            'source_key' => fn (array $attributes): string => 'subscription-fee:'.$attributes['subscription_id'],
            'amount' => '50.00',
            'currency_amount' => fn (array $attributes): string => ltrim((string) $attributes['amount'], '-'),
        ];
    }
}
