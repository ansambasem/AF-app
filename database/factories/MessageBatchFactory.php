<?php

namespace Database\Factories;

use App\Enums\MessageChannel;
use App\Enums\MessageKind;
use App\Models\Branch;
use App\Models\MessageBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MessageBatch> */
class MessageBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'kind' => MessageKind::Custom,
            'channel' => MessageChannel::Sms,
            'body' => 'عزيزي {الاسم}، '.fake()->sentence(),
        ];
    }

    /**
     * Sent from staff members' WhatsApp.
     */
    public function whatsApp(): static
    {
        return $this->state(fn () => ['channel' => MessageChannel::WhatsApp]);
    }
}
