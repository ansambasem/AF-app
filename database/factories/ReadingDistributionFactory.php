<?php

namespace Database\Factories;

use App\Enums\ReadingAssignmentChannel;
use App\Models\MeterBox;
use App\Models\ReadingDistribution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReadingDistribution>
 */
class ReadingDistributionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meter_box_id' => MeterBox::factory(),
            'branch_id' => fn (array $attributes): int => MeterBox::findOrFail($attributes['meter_box_id'])->branch_id,
            'channel' => ReadingAssignmentChannel::Mobile,
            'user_id' => fn (array $attributes): int => User::factory()->dataEntry()->create(['branch_id' => $attributes['branch_id']])->id,
            'approved_by' => fn (array $attributes): int => User::factory()->branchAdmin()->create(['branch_id' => $attributes['branch_id']])->id,
        ];
    }
}
