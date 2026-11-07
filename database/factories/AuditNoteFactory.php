<?php

namespace Database\Factories;

use App\Models\AuditNote;
use App\Models\Closing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditNote>
 */
class AuditNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'closing_id' => Closing::factory()->submitted(), 'body' => 'يرجى توضيح فرق الجرد.', 'status' => 'awaiting_reply',
        ];
    }
}
