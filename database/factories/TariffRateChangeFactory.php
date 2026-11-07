<?php

namespace Database\Factories;

use App\Models\Tariff;
use App\Models\TariffRateChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TariffRateChange>
 */
class TariffRateChangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tariff_id' => Tariff::factory(),
            'rate' => fake()->randomFloat(2, 1, 6),
        ];
    }
}
