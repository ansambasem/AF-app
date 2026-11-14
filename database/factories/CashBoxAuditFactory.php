<?php

namespace Database\Factories;

use App\Models\CashBox;
use App\Models\CashBoxAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashBoxAudit>
 */
class CashBoxAuditFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cash_box_id' => CashBox::factory()->state(['status' => 'received', 'closed_at' => now(), 'confirmed_at' => now()]),
            'audited_by' => User::factory()->financialAuditor(),
            'audited_at' => now(),
        ];
    }
}
