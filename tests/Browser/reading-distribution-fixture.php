<?php

use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\MeterReading;
use App\Models\ReadingAssignment;
use App\Models\ReadingCycle;
use App\Models\SubArea;
use App\Models\Subscription;
use App\Models\User;

/** This fixture is only run by the browser harness against its temporary database. */
if (config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'powercollect-browser-')) {
    throw new RuntimeException('Browser fixtures require the isolated test database.');
}

$branch = Branch::factory()->create(['name' => 'مخيم 2']);
$owner = User::query()->where('username', 'owner')->firstOrFail();
$readers = collect(['محمد', 'عمر', 'خليل'])->map(fn (string $name): User => User::factory()->dataEntry()->create(['branch_id' => $branch->id, 'name' => $name]));
$cycle = ReadingCycle::factory()->create(['branch_id' => $branch->id, 'created_by' => $owner->id]);

foreach (['أبو ذر', 'الشافعي', 'السوق', 'المدارس', 'المخيم'] as $areaIndex => $name) {
    $area = SubArea::factory()->create(['name' => $name]);
    for ($offset = 0; $offset < 20; $offset++) {
        $index = $areaIndex * 20 + $offset;
        $box = MeterBox::factory()->create(['branch_id' => $branch->id, 'sub_area_id' => $area->id, 'name' => $name, 'name_suffix' => (string) ($offset + 1), 'box_number' => (string) (110301 + $index)]);
        Subscription::factory()->create(['branch_id' => $branch->id, 'meter_box_id' => $box->id, 'circuit_breaker_id' => null, 'registered_by' => $owner->id]);
        if ($index >= 90) {
            continue;
        }
        $assignment = ReadingAssignment::factory();
        if ($index < 70) {
            $assignment = $assignment->mobile($readers[$index < 20 ? 0 : ($index < 40 ? 1 : 2)]);
        }
        if ($index < 60 || $index >= 70) {
            $assignment = $assignment->approved();
        }
        $allocation = $assignment->create(['reading_cycle_id' => $cycle->id, 'branch_id' => $branch->id, 'meter_box_id' => $box->id, 'assigned_by' => $owner->id]);
        if ($index === 0) {
            $allocation->update(['downloaded_at' => now(), 'downloaded_version' => $allocation->version]);
        }
    }
}

$messageSubscription = Subscription::query()->where('branch_id', $branch->id)->firstOrFail();
$baselineSubscription = Subscription::query()->where('branch_id', $branch->id)->whereKeyNot($messageSubscription->id)->firstOrFail();
$baselineSubscription->update(['initial_reading' => 123]);
$messageWeek = MeterReading::latestEndedWeekStart($branch)->subWeeks(12)->toDateString();
MeterReading::factory()->approved()->for($messageSubscription)->create([
    'week_start' => $messageWeek, 'previous_reading' => 100, 'current_reading' => 125,
    'consumption' => 25, 'unit_price' => 1.75, 'recorded_by' => $owner->id,
]);

file_put_contents(getenv('OUT_DIR').'/fixture.json', json_encode([
    'cycleId' => $cycle->id, 'readerId' => $readers[0]->id, 'branchId' => $branch->id,
    'messageWeek' => $messageWeek, 'messageSubscriptionName' => $messageSubscription->displayName(),
    'baselineSubscriptionName' => $baselineSubscription->displayName(), 'baselineAccountNumber' => $baselineSubscription->account_number,
], JSON_THROW_ON_ERROR));
