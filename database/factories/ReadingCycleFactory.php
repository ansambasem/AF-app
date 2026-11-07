<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\MeterReading;
use App\Models\ReadingCycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The branch's latest ended reading week, read on its reading day.
 *
 * @extends Factory<ReadingCycle>
 */
class ReadingCycleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'week_start' => fn (array $attributes) => MeterReading::latestEndedWeekStart($attributes['branch_id'])->toDateString(),
            'week_end' => fn (array $attributes) => MeterReading::weekEndFor(MeterReading::latestEndedWeekStart($attributes['branch_id']), $attributes['branch_id'])->toDateString(),
            'reading_date' => fn (array $attributes) => $attributes['week_end'],
            'created_by' => User::factory(),
        ];
    }
}
