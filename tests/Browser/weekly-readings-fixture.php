<?php

use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\ReadingEntrySetting;
use App\Models\StandingDiscount;
use App\Models\Subscription;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Support\Carbon;

if (config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'powercollect-browser-') || ! getenv('BROWSER_TEST_CLOCK')) {
    throw new RuntimeException('Weekly reading browser fixtures require the isolated test database and clock.');
}

$owner = User::query()->where('username', 'owner')->firstOrFail();
$branch = Branch::factory()->create(['name' => 'فرع اختبار دورة القراءات']);
$box = MeterBox::factory()->for($branch)->create(['box_number' => 'AUTO-1']);
$tariff = Tariff::factory()->residential()->create(['rate' => '0.50']);
ReadingEntrySetting::factory()->forBranch($branch)->forcedOpen()->create(['reading_day' => 4]);
$subscriptions = collect(['مشترك اختبار القراءات', 'مشترك اختبار الخصم'])->map(fn (string $name): Subscription => Subscription::factory()->for($branch)->create([
    'full_name' => $name, 'meter_box_id' => $box->id, 'tariff_id' => $tariff->id,
    'initial_reading' => 1000, 'minimum_charge' => 0, 'circuit_breaker_id' => null,
    'subscription_fee' => 0, 'registered_by' => $owner->id, 'subscription_date' => '2026-09-01',
]));
StandingDiscount::factory()->for($subscriptions[1])->percentage(20)->create(['granted_by' => $owner->id]);
$firstReadingDay = Carbon::parse(trim(file_get_contents(getenv('BROWSER_TEST_CLOCK'))));

file_put_contents(getenv('OUT_DIR').'/fixture.json', json_encode([
    'branchId' => $branch->id,
    'readingDays' => collect(range(0, 3))->map(fn (int $week): string => $firstReadingDay->copy()->addWeeks($week)->toDateString())->all(),
    'weekStarts' => collect(range(0, 3))->map(fn (int $week): string => $firstReadingDay->copy()->addWeeks($week)->subDays(6)->toDateString())->all(),
    'subscribers' => $subscriptions->map(fn (Subscription $subscription): array => [
        'id' => $subscription->id, 'name' => $subscription->displayName(), 'account' => $subscription->account_number,
    ])->all(),
], JSON_THROW_ON_ERROR));
