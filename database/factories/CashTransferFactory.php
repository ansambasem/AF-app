<?php

namespace Database\Factories;

use App\Enums\CashTransferMethod;
use App\Enums\CashTransferStatus;
use App\Models\CashTransfer;
use App\Models\Closing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashTransfer>
 */
class CashTransferFactory extends Factory
{
    /**
     * Cash on its way from a branch to the company.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'closing_id' => Closing::factory()->approved(),
            'branch_id' => fn (array $attributes) => Closing::find($attributes['closing_id'])->branch_id,
            'amount' => '100.00',
            'method' => CashTransferMethod::HandDelivery,
            'sent_by' => User::factory(),
            'recipient_id' => User::factory()->superAdmin(),
            'sent_at' => now(),
            'proof_path' => 'cash-transfers/proof.jpg',
            'status' => CashTransferStatus::InTransit,
        ];
    }
}
