<?php

namespace Tests\Feature;

use App\Enums\MeterReadingStatus;
use App\Models\Branch;
use App\Models\MeterReading;
use App\Models\ReadingEntrySetting;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\CampTwoReadingSamplesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CampTwoReadingSamplesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepares_pending_readings_for_previous_reading_edits_without_charging_or_replacing_existing_work(): void
    {
        $this->travelTo('2026-10-24 10:00:00');
        $branch = Branch::factory()->create(['seed_key' => 'camp-two']);
        ReadingEntrySetting::factory()->forBranch($branch)->create(['reading_day' => 5, 'open_days' => [5]]);
        $recorder = User::factory()->superAdmin()->create();
        $subscription = Subscription::factory()->for($branch)->create(['account_number' => '202600021', 'initial_reading' => 400]);
        $older = MeterReading::recordFor($subscription, now()->parse('2026-10-03'), 407, $recorder, 'Keep this reading');
        $initial = Subscription::factory()->for($branch)->create(['account_number' => '202600022', 'initial_reading' => 33]);
        $foreign = Subscription::factory()->create(['account_number' => '202600012']);
        $archived = Subscription::factory()->for($branch)->create(['account_number' => '202600015', 'archived_at' => now()]);

        $this->seed(CampTwoReadingSamplesSeeder::class);

        $current = $subscription->meterReadings()->whereDate('week_start', '2026-10-17')->sole();
        $previous = $subscription->meterReadings()->whereDate('week_start', '2026-10-10')->sole();
        $this->assertSame([407.0, 425.0, MeterReadingStatus::Pending], [$current->previous_reading, $current->current_reading, $current->status]);
        $this->assertSame(407.0, $previous->current_reading);
        $this->assertTrue($recorder->can('adjustLastReading', $previous));
        $this->assertSame(45.0, $initial->meterReadings()->whereDate('week_start', '2026-10-17')->sole()->current_reading);
        $this->assertSame(0, $foreign->meterReadings()->count());
        $this->assertSame(0, $archived->meterReadings()->count());
        $this->assertSame('Keep this reading', $older->fresh()->notes);
        $this->assertDatabaseCount('meter_readings', 5);
        $this->assertDatabaseCount('subscription_transactions', 0);

        $current->update(['current_reading' => 426, 'notes' => 'Edited by the tester']);
        $this->seed(CampTwoReadingSamplesSeeder::class);

        $this->assertSame([426.0, 'Edited by the tester'], [$current->fresh()->current_reading, $current->fresh()->notes]);
        $this->assertDatabaseCount('meter_readings', 5);
        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_sample_readings_are_refused_outside_local_and_testing_environments(): void
    {
        app()->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sample readings are only available locally or in tests.');

        app(CampTwoReadingSamplesSeeder::class)->run();
    }
}
