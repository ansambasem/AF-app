<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CashBoxSync;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashBoxSync>
 */
class CashBoxSyncFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'user_id' => User::factory(),
            'synced_at' => now(),
            'pending_payments' => 0,
        ];
    }
}
