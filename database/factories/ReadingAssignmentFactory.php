<?php

namespace Database\Factories;

use App\Enums\ReadingAssignmentChannel;
use App\Enums\ReadingAssignmentStatus;
use App\Models\MeterBox;
use App\Models\ReadingAssignment;
use App\Models\ReadingCycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A box of the cycle's branch on the paper sheets, as a draft; give a
 * reader with mobile().
 *
 * @extends Factory<ReadingAssignment>
 */
class ReadingAssignmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reading_cycle_id' => ReadingCycle::factory(),
            'branch_id' => fn (array $attributes) => ReadingCycle::query()->findOrFail($attributes['reading_cycle_id'])->branch_id,
            'meter_box_id' => fn (array $attributes) => MeterBox::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'channel' => ReadingAssignmentChannel::Paper,
            'status' => ReadingAssignmentStatus::Draft,
            'assigned_by' => User::factory(),
        ];
    }

    public function mobile(User $reader): static
    {
        return $this->state(['channel' => ReadingAssignmentChannel::Mobile, 'user_id' => $reader->id]);
    }

    public function approved(): static
    {
        return $this->state(['status' => ReadingAssignmentStatus::Approved, 'approved_at' => now()]);
    }
}
