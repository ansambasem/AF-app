<?php

namespace Database\Factories;

use App\Models\TariffSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TariffSegment>
 */
class TariffSegmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
        ];
    }
}
