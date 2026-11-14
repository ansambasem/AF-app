<?php

namespace Database\Factories;

use App\Models\BankTransferAudit;
use App\Models\CashBox;
use App\Models\CashBoxPayment;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankTransferAudit>
 */
class BankTransferAuditFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cash_box_payment_id' => function (): int {
                $box = CashBox::factory()->bankTransfer()->create(['status' => 'received', 'closed_at' => now(), 'confirmed_at' => now()]);
                $payment = SubscriptionTransaction::withoutEvents(fn (): SubscriptionTransaction => SubscriptionTransaction::factory()->for(Subscription::factory()->state(['branch_id' => $box->branch_id]))->create([
                    'branch_id' => $box->branch_id, 'recorded_by' => $box->user_id,
                    'type' => 'payment', 'status' => 'active', 'amount' => '-100.00', 'currency_amount' => '100.00', 'currency' => 'ILS', 'exchange_rate' => '1', 'payment_method' => 'bank_transfer',
                ]));

                return CashBoxPayment::factory()->for($box, 'box')->for($payment, 'transaction')->create(['method' => 'bank_transfer'])->id;
            },
            'audited_by' => User::factory()->financialAuditor(),
            'currency' => 'ILS', 'recorded_amount' => '100.00', 'bank_amount' => '100.00',
            'exchange_rate' => '1.0000', 'difference' => '0.00', 'outcome' => 'matched', 'audited_at' => now(),
        ];
    }
}
