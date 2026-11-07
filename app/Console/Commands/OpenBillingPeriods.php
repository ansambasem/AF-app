<?php

namespace App\Console\Commands;

use App\Models\BillingPeriod;
use App\Support\BillingPeriods;
use App\Support\DailySeries;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing-periods:open')]
#[Description('Open each branch’s billing period on its first day, closing the one before it')]
class OpenBillingPeriods extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BillingPeriods $periods): int
    {
        BillingPeriod::withoutGlobalScopes()->whereNull('opened_at')->whereDate('starts_on', '<=', DailySeries::today()->toDateString())
            ->distinct()->pluck('branch_id')->each(fn (int $branchId) => $periods->refresh($branchId));

        return self::SUCCESS;
    }
}
