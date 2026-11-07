<?php

use App\Enums\ChargeType;
use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BranchClosingWorkflow;
use Illuminate\Support\Facades\Date;

$north = Branch::factory()->create(['name' => 'فرع التقارير الشمالي']);
$south = Branch::factory()->create(['name' => 'فرع التقارير الجنوبي']);
$manager = User::factory()->branchAdmin()->for($north)->create();
$collector = User::factory()->collector()->for($north)->create();
app(BranchClosingWorkflow::class)->start($north->id, $manager);
$northBox = MeterBox::factory()->for($north)->create();
$southBox = MeterBox::factory()->for($south)->create();
$owed = Subscription::factory()->for($north)->create(['full_name' => 'مشترك مدين', 'meter_box_id' => $northBox->id]);
$credited = Subscription::factory()->for($north)->create(['full_name' => 'مشترك دائن', 'meter_box_id' => $northBox->id]);
$foreign = Subscription::factory()->for($south)->create(['full_name' => 'مشترك الفرع الآخر', 'meter_box_id' => $southBox->id]);
SubscriptionTransaction::recordCharge($owed, $collector, ChargeType::Penalty, '300', null);
SubscriptionTransaction::recordCharge($credited, $collector, ChargeType::Penalty, '100', null);
SubscriptionTransaction::recordCharge($foreign, $collector, ChargeType::Penalty, '500', null);
SubscriptionTransaction::recordPayment($owed, $collector, ['amount' => '100', 'currency' => 'ILS', 'payment_method' => 'cash']);
SubscriptionTransaction::recordPayment($credited, $collector, ['amount' => '150', 'currency' => 'ILS', 'payment_method' => 'cash']);
SubscriptionTransaction::recordPayment($owed, $collector, ['amount' => '10', 'currency' => 'USD', 'exchange_rate' => '3.70', 'payment_method' => 'cash']);
$quiet = Subscription::factory()->for($north)->create(['full_name' => 'حساب بلا حركات في الفترة', 'meter_box_id' => $northBox->id, 'registered_by' => $collector->id]);
Date::setTestNow(now()->subDays(2));
SubscriptionTransaction::recordCharge($quiet, $collector, ChargeType::Penalty, '7178', null);
Date::setTestNow(null);
$many = Subscription::factory()->for($north)->create(['full_name' => 'حساب متعدد الحركات', 'meter_box_id' => $northBox->id, 'registered_by' => $collector->id]);
foreach (range(1, 20) as $index) {
    SubscriptionTransaction::recordCharge($many, $collector, ChargeType::Penalty, '1', null);
}
foreach (range(1, 35) as $index) {
    $directoryAccount = Subscription::factory()->for($north)->create(['full_name' => 'مشترك الدليل '.$index, 'circuit_breaker_id' => $owed->circuit_breaker_id, 'meter_box_id' => $northBox->id, 'registered_by' => $collector->id]);
}
file_put_contents(getenv('OUT_DIR').'/reports-fixture.json', json_encode(['north' => $north->id, 'south' => $south->id, 'credited' => $credited->id, 'quiet' => $quiet->id, 'many' => $many->id, 'directoryLastAccount' => $directoryAccount->account_number], JSON_THROW_ON_ERROR));
