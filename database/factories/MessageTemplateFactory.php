<?php

namespace Database\Factories;

use App\Enums\MessageKind;
use App\Models\MessageTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MessageTemplate> */
class MessageTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'kind' => fake()->randomElement(MessageKind::cases()),
            'body' => 'عزيزي {الاسم}، '.fake()->sentence(),
        ];
    }
}
