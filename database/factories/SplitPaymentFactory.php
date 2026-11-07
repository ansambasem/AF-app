<?php

namespace Database\Factories;

use App\Models\SplitPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SplitPayment>
 */
class SplitPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference_number' => 'REF-'.fake()->unique()->numerify('######'),
            'bank_name' => 'بنك فلسطين',
            'sender_name' => fake()->name(),
            'total_amount' => 1000,
            'recorded_by' => User::factory(),
        ];
    }
}
