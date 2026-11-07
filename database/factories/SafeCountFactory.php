<?php

namespace Database\Factories;

use App\Models\Closing;
use App\Models\SafeCount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SafeCount>
 */
class SafeCountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'closing_id' => Closing::factory(), 'user_id' => User::factory(),
            'counts' => ['ILS' => '0.00', 'USD' => '0.00', 'JOD' => '0.00'], 'expected' => ['ILS' => '0.00', 'USD' => '0.00', 'JOD' => '0.00'], 'differences' => ['ILS' => '0.00', 'USD' => '0.00', 'JOD' => '0.00'],
        ];
    }
}
