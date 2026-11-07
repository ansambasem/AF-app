<?php

namespace Database\Factories;

use App\Enums\ClosingStatus;
use App\Enums\ClosingType;
use App\Models\Branch;
use App\Models\Closing;
use App\Support\ClosingPeriods;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Closing>
 */
class ClosingFactory extends Factory
{
    /**
     * A draft daily closing for yesterday.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $day = ClosingPeriods::for()->latestEndedDay()->toDateString();

        return [
            'number' => (string) fake()->unique()->numberBetween(5001, 999999),
            'type' => ClosingType::Daily,
            'branch_id' => Branch::factory(),
            'period_start' => $day,
            'period_end' => $day,
            'status' => ClosingStatus::Draft,
        ];
    }

    public function forDay(string $day): static
    {
        return $this->state(fn (): array => ['period_start' => $day, 'period_end' => $day]);
    }

    public function submitted(): static
    {
        return $this->state(fn (): array => ['status' => ClosingStatus::Submitted, 'counted_cash' => '0.00', 'opening_cash' => '0.00', 'submitted_at' => now()]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => ClosingStatus::Approved, 'counted_cash' => '0.00', 'opening_cash' => '0.00', 'submitted_at' => now(), 'reviewed_at' => now()]);
    }
}
