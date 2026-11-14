<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ClosingSettingChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClosingSettingChange>
 */
class ClosingSettingChangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(), 'user_id' => User::factory(),
            'settings' => ['frequency' => 'weekly', 'arrangement' => 'combined', 'week_starts_on' => 6, 'cutoff_time' => '00:00'],
            'effective_from' => now()->addWeek(),
        ];
    }
}
