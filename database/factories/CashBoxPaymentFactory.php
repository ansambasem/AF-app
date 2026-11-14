<?php

namespace Database\Factories;

use App\Models\CashBox;
use App\Models\CashBoxPayment;
use App\Models\SubscriptionTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashBoxPayment>
 */
class CashBoxPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cash_box_id' => CashBox::factory(),
            'subscription_transaction_id' => SubscriptionTransaction::factory()->state(['type' => 'payment', 'amount' => '-100', 'currency' => 'ILS', 'payment_method' => 'cash']),
            'currency' => 'ILS', 'amount' => '100.00', 'method' => 'cash', 'status' => 'cash',
            'occurred_at' => now(), 'synced_at' => now(),
        ];
    }
}
