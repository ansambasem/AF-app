<?php

namespace Database\Factories;

use App\Models\Closing;
use App\Models\CollectorDebt;
use App\Models\DebtRepayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DebtRepayment>
 */
class DebtRepaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'collector_debt_id' => CollectorDebt::factory(), 'closing_id' => Closing::factory(), 'received_by' => User::factory(), 'amount' => '5.00',
        ];
    }
}
