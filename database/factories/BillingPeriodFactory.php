<?php

namespace Database\Factories;

use App\Models\BillingPeriod;
use App\Models\Branch;
use App\Support\DailySeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A branch's open billing period: the week starting today.
 *
 * @extends Factory<BillingPeriod>
 */
class BillingPeriodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'starts_on' => DailySeries::today()->toDateString(),
            'ends_on' => DailySeries::today()->addDays(6)->toDateString(),
            'opened_at' => now(),
        ];
    }

    /** Waiting for its first day. */
    public function scheduled(): static
    {
        return $this->state(['opened_at' => null]);
    }

    /** Closed, waiting for the manager's approval. */
    public function closed(): static
    {
        return $this->state(['closed_at' => now()]);
    }
}
