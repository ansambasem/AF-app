<?php

namespace Database\Factories;

use App\Enums\DiscountMethod;
use App\Models\StandingDiscount;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StandingDiscount>
 */
class StandingDiscountFactory extends Factory
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
            'method' => DiscountMethod::Percentage,
            'value' => fake()->numberBetween(5, 50),
            'granted_by' => User::factory(),
        ];
    }

    /**
     * The given percentage off every reading.
     */
    public function percentage(float $percent): static
    {
        return $this->state(fn () => ['method' => DiscountMethod::Percentage, 'value' => $percent]);
    }

    /**
     * The given kilowatts off every reading's consumption.
     */
    public function kilowatts(float $kilowatts): static
    {
        return $this->state(fn () => ['method' => DiscountMethod::Kilowatt, 'value' => $kilowatts]);
    }

    /**
     * The given shekels off the kilo price.
     */
    public function shekelsOffKiloPrice(float $shekels): static
    {
        return $this->state(fn () => ['method' => DiscountMethod::Shekel, 'value' => $shekels]);
    }
}
