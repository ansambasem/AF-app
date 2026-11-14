<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CollectorDebt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CollectorDebt>
 */
class CollectorDebtFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cash_box_id' => CashBox::factory(), 'user_id' => User::factory(), 'branch_id' => Branch::factory(),
            'currency' => 'ILS', 'amount' => '10.00', 'remaining' => '10.00',
        ];
    }
}
