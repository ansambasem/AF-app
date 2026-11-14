<?php

namespace App\Support;

use App\Enums\ReadingAssignmentChannel;
use App\Enums\ReadingAssignmentStatus;
use App\Models\Branch;
use App\Models\ReadingAssignment;
use App\Models\ReadingCycle;
use App\Models\ReadingDistribution;
use App\Models\ReadingEntrySetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecurringReadingDistribution
{
    /** @return array{weekStart: Carbon, weekEnd: Carbon, readingDate: Carbon, cycle: ?ReadingCycle} */
    public function upcoming(Branch $branch): array
    {
        $setting = ReadingEntrySetting::forBranch($branch);
        $today = DailySeries::today()->toDateString();
        $date = Carbon::parse($today);

        do {
            $weekStart = $setting->weekStartFor($date);
            $weekEnd = $setting->weekEndFor($date);
            $cycle = ReadingCycle::query()->where('branch_id', $branch->id)->whereDate('week_start', $weekStart->toDateString())->first();
            $date = $weekEnd->copy()->addDay();
        } while ($cycle !== null && ($cycle->isClosed() || $cycle->reading_date->toDateString() < $today));

        $readingDate = $cycle?->reading_date ?? $weekEnd;
        $delayed = ReadingCycle::query()->where('branch_id', $branch->id)->whereNull('closed_at')
            ->whereDate('week_start', '<', $weekStart->toDateString())
            ->whereDate('reading_date', '>=', $today)->whereDate('reading_date', '<=', $readingDate->toDateString())
            ->orderBy('reading_date')->first();

        return $delayed === null
            ? compact('weekStart', 'weekEnd', 'readingDate', 'cycle')
            : ['weekStart' => $delayed->week_start, 'weekEnd' => $delayed->week_end, 'readingDate' => $delayed->reading_date, 'cycle' => $delayed];
    }

    /**
     * Prepare the upcoming reading week from the standing distribution.
     * Existing assignments, including this week's exceptions, stay in place.
     */
    public function prepare(Branch $branch, ?User $actor = null, bool $onlyWhenAvailable = true, ?ReadingCycle $scheduledCycle = null): ?ReadingCycle
    {
        $setting = ReadingEntrySetting::forBranch($branch);
        $today = DailySeries::today();
        $upcoming = $scheduledCycle === null ? $this->upcoming($branch) : null;
        $weekStart = $scheduledCycle?->week_start ?? $upcoming['weekStart'];
        $readingDate = $scheduledCycle?->reading_date ?? $upcoming['readingDate'];

        return DB::transaction(function () use ($branch, $actor, $weekStart, $readingDate, $onlyWhenAvailable, $today, $setting): ?ReadingCycle {
            Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $existing = ReadingCycle::query()->where('branch_id', $branch->id)->whereDate('week_start', $weekStart->toDateString())->first();
            $availableOn = ($existing?->reading_date ?? $readingDate)->copy()->subDays($setting->assignment_lead_days ?? 1);

            if ($onlyWhenAvailable && $today->toDateString() < $availableOn->toDateString()) {
                return null;
            }

            $defaults = ReadingDistribution::query()->with('approvedBy')->where('branch_id', $branch->id)->orderBy('id')->get();
            $createdBy = $actor?->id ?? $defaults->first()?->approved_by;

            if ($createdBy === null) {
                return null;
            }

            $cycle = $existing
                ?? ReadingCycle::create([
                    'branch_id' => $branch->id, 'week_start' => $weekStart->toDateString(),
                    'week_end' => $readingDate->toDateString(), 'reading_date' => $readingDate->toDateString(),
                    'created_by' => $createdBy,
                ]);

            if ($cycle->isClosed()) {
                return $cycle;
            }

            $eligibleBoxes = $cycle->eligibleMeterBoxes()->pluck('id')->flip();
            $eligibleReaders = $cycle->eligibleReaders()->pluck('id')->flip();

            foreach ($defaults as $default) {
                if (! $eligibleBoxes->has($default->meter_box_id)
                    || ($default->channel === ReadingAssignmentChannel::Mobile && ! $eligibleReaders->has($default->user_id))) {
                    continue;
                }

                $assignment = ReadingAssignment::firstOrCreate([
                    'reading_cycle_id' => $cycle->id, 'meter_box_id' => $default->meter_box_id,
                ], [
                    'branch_id' => $branch->id, 'channel' => $default->channel, 'user_id' => $default->user_id,
                    'status' => ReadingAssignmentStatus::Approved, 'version' => 1,
                    'assigned_by' => $default->approved_by, 'approved_by' => $default->approved_by,
                    'approved_at' => now(),
                ]);

                if ($assignment->wasRecentlyCreated) {
                    $assignment->recordEvent('assigned', $default->approvedBy);
                    $assignment->recordEvent('approved', $default->approvedBy);
                }
            }

            return $cycle;
        });
    }
}
