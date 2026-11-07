<?php

namespace Database\Factories;

use App\Models\Closing;
use App\Models\ClosingVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClosingVersion>
 */
class ClosingVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'closing_id' => Closing::factory()->submitted(), 'user_id' => User::factory(), 'version' => 1, 'snapshot' => ['boxes' => [], 'electronic' => []],
        ];
    }
}
