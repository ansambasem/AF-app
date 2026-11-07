<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\ReadingCycle;
use App\Models\ReadingDistribution;
use App\Models\ReadingEntrySetting;
use App\Support\DailySeries;
use App\Support\RecurringReadingDistribution;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('readings:prepare-distributions')]
#[Description('Prepare weekly reading assignments from each branch’s standing distribution')]
class PrepareReadingDistributions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RecurringReadingDistribution $distribution): int
    {
        $prepared = 0;
        Branch::query()->where('is_active', true)->whereIn('id', ReadingDistribution::query()->select('branch_id')->distinct())
            ->eachById(function (Branch $branch) use ($distribution, &$prepared): void {
                $didPrepare = $distribution->prepare($branch) !== null;
                $weekStart = ReadingEntrySetting::forBranch($branch)->weekStartFor(DailySeries::today());
                $delayed = ReadingCycle::query()->where('branch_id', $branch->id)->whereNull('closed_at')
                    ->whereDate('week_start', '<', $weekStart->toDateString())
                    ->whereDate('reading_date', '>=', DailySeries::today()->toDateString())->get();

                foreach ($delayed as $cycle) {
                    $didPrepare = ($distribution->prepare($branch, scheduledCycle: $cycle) !== null) || $didPrepare;
                }

                if ($didPrepare) {
                    $prepared++;
                }
            });

        $this->info('Prepared reading distributions for '.$prepared.' branches.');

        return self::SUCCESS;
    }
}
