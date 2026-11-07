<?php

namespace Database\Factories;

use App\Enums\TariffCategory;
use App\Models\Tariff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tariff>
 */
class TariffFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category' => fake()->randomElement(TariffCategory::cases()),
            'rate' => fake()->randomFloat(2, 10, 200),
        ];
    }

    public function residential(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => TariffCategory::Residential,
        ]);
    }

    public function commercial(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => TariffCategory::Commercial,
        ]);
    }
}
