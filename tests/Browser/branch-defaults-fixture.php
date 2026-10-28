<?php

use App\Models\Branch;
use App\Models\CashBoxPayment;
use App\Models\MeterBox;
use App\Models\ReadingEntrySetting;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BranchClosingWorkflow;

foreach (['فرع ألف', 'فرع باء'] as $index => $name) {
    $branch = Branch::factory()->create(['name' => $name]);
    ReadingEntrySetting::factory()->forBranch($branch)->create(['reading_day' => $index === 0 ? 4 : 6]);
    $manager = User::factory()->branchAdmin()->for($branch)->create();
    $collector = User::factory()->collector()->for($branch)->create();
    app(BranchClosingWorkflow::class)->start($branch->id, $manager);
    $subscription = Subscription::factory()->for($branch)->create([
        'registered_by' => $collector->id, 'meter_box_id' => MeterBox::factory()->for($branch)->create()->id,
    ]);
    foreach (['100', '25'] as $amount) {
        $payment = SubscriptionTransaction::recordPayment($subscription, $collector, ['amount' => $amount, 'currency' => 'ILS', 'payment_method' => 'cash']);
        if ($amount === '100') {
            CashBoxPayment::query()->where('subscription_transaction_id', $payment->id)->sole()->box
                ->update(['status' => 'received', 'closed_at' => now(), 'received_at' => now()]);
        }
    }
}
