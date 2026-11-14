<?php

namespace Database\Factories;

use App\Enums\MeterReadingStatus;
use App\Models\MeterReading;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<MeterReading>
 */
class MeterReadingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $previousReading = fake()->numberBetween(0, 5000);
        $consumption = fake()->numberBetween(5, 150);

        return [
            'subscription_id' => Subscription::factory(),
            'branch_id' => fn (array $attributes) => Subscription::find($attributes['subscription_id'])->branch_id,
            'week_start' => fn (array $attributes) => MeterReading::weekStartFor(now(), $attributes['branch_id']),
            'week_end' => fn (array $attributes) => MeterReading::weekEndFor(Carbon::parse($attributes['week_start']), $attributes['branch_id']),
            'previous_reading' => $previousReading,
            'current_reading' => $previousReading + $consumption,
            'consumption' => $consumption,
            'status' => MeterReadingStatus::Pending,
            'recorded_by' => User::factory(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => MeterReadingStatus::Approved]);
    }
}
