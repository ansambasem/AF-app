<?php

namespace Database\Factories;

use App\Models\AuditNote;
use App\Models\Closing;
use App\Models\ClosingAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClosingAdjustment>
 */
class ClosingAdjustmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'closing_id' => Closing::factory(), 'original_closing_id' => Closing::factory()->submitted(), 'audit_note_id' => AuditNote::factory(), 'user_id' => User::factory(), 'book' => 'cash', 'currency' => 'ILS', 'amount' => '5.00', 'reason' => 'تصحيح جرد سابق',
        ];
    }
}
