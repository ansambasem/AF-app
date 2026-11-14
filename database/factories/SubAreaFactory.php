<?php

namespace Database\Factories;

use App\Models\SubArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubArea>
 */
class SubAreaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->streetName(),
        ];
    }
}
