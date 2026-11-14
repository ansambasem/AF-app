<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\MeterBox;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeterBox>
 */
class MeterBoxFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'box_number' => fake()->unique()->bothify('BOX-####'),
            'branch_id' => Branch::factory(),
            'location' => fake()->streetAddress(),
        ];
    }
}
