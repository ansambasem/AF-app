<?php

namespace Database\Factories;

use App\Enums\ReadingEntryMode;
use App\Models\Branch;
use App\Models\ReadingEntrySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReadingEntrySetting>
 */
class ReadingEntrySettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'open_days' => ReadingEntrySetting::DEFAULT_OPEN_DAYS,
            'opens_at' => ReadingEntrySetting::DEFAULT_OPENS_AT,
            'closes_at' => ReadingEntrySetting::DEFAULT_CLOSES_AT,
            'reading_day' => ReadingEntrySetting::DEFAULT_READING_DAY,
            'mode' => ReadingEntryMode::Automatic,
        ];
    }

    /**
     * The branch's own schedule, rather than the company's.
     */
    public function forBranch(Branch|int $branch): static
    {
        return $this->state(fn () => ['branch_id' => $branch instanceof Branch ? $branch->id : $branch]);
    }

    public function forcedOpen(): static
    {
        return $this->state(fn () => ['mode' => ReadingEntryMode::Open]);
    }

    public function forcedClosed(): static
    {
        return $this->state(fn () => ['mode' => ReadingEntryMode::Closed]);
    }
}
