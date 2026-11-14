<?php

namespace Database\Factories;

use App\Models\AuditNote;
use App\Models\AuditNoteReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditNoteReply>
 */
class AuditNoteReplyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'audit_note_id' => AuditNote::factory(), 'user_id' => User::factory(), 'kind' => 'clarification', 'body' => 'توضيح الملاحظة.',
        ];
    }
}
