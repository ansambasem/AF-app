<?php

namespace Database\Factories;

use App\Models\CircuitBreaker;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CircuitBreaker> */
class CircuitBreakerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ampere' => fake()->unique()->randomElement([2, 4, 6, 10, 16, 20, 25, 32, 40, 63]),
            'minimum_payment' => fake()->randomFloat(2, 5, 100),
        ];
    }
}
