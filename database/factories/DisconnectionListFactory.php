<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\DisconnectionList;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DisconnectionList>
 */
class DisconnectionListFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'title' => 'قائمة قطع '.fake()->monthName(),
            'notes' => null,
            'created_by' => User::factory(),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => ['closed_at' => now(), 'closed_by' => $attributes['created_by']]);
    }
}
