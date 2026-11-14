<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\CircuitBreaker;
use App\Models\MeterBox;
use App\Models\Subscription;
use App\Models\SubscriberProfile;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * The model's creating hook normally assigns the account number, but
     * DatabaseSeeder runs with model events disabled — assign it here in
     * that case, one saved subscription at a time so numbers never collide.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Subscription $subscription): void {
            if ($subscription->subscriber_profile_id === null) {
                $subscription->profile()->associate(SubscriberProfile::create($subscription->only(SubscriberProfile::PERSONAL_FIELDS)));
                $subscription->saveQuietly();
            }

            if ($subscription->account_number === null) {
                $subscription->forceFill(['account_number' => Subscription::nextAccountNumber()])->saveQuietly();
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'national_id' => fake()->unique()->numerify('#########'),
            'initial_reading' => fake()->numberBetween(0, 10000),
            'phone' => fake()->numerify('05'.fake()->randomElement(['6', '9']).'#######'),
            'address' => fake()->address(),
            'meter_box_id' => MeterBox::factory(),
            // Tariffs are fixed reference data (only Home/Business ever
            // exist) — reuse one instead of risking a unique-category
            // collision when creating several subscriptions at once.
            'tariff_id' => fn () => Tariff::query()->inRandomOrder()->value('id') ?? Tariff::factory()->residential()->create()->id,
            'branch_id' => Branch::factory(),
            'registered_by' => User::factory(),
            'status' => SubscriptionStatus::Active,
            'circuit_breaker_id' => CircuitBreaker::factory(),
            'minimum_charge' => fake()->randomFloat(2, 5, 50),
            'notes' => fake()->sentence(),
        ];
    }
}
