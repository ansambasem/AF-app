<?php

namespace App\Console\Commands;

use App\Models\ClosingSetting;
use App\Support\BranchClosingWorkflow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('closings:process')]
#[Description('Close branch sessions at cutoff and open the next contiguous period')]
class ProcessBranchClosings extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BranchClosingWorkflow $workflow): int
    {
        foreach (ClosingSetting::query()->whereNotNull('branch_id')->whereNotNull('workflow_started_at')->get() as $setting) {
            $workflow->refresh($setting->branch_id);
        }

        return self::SUCCESS;
    }
}
