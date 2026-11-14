<?php

namespace Database\Factories;

use App\Models\SubscriberProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubscriberProfile> */
class SubscriberProfileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'national_id' => fake()->unique()->numerify('#########'),
            'phone' => fake()->numerify('059#######'),
            'address' => fake()->address(),
        ];
    }
}
