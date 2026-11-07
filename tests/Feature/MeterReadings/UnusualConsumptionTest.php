<?php

namespace Tests\Feature\MeterReadings;

use App\Enums\MeterReadingStatus;
use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\MeterReading;
use App\Models\MobileAccessToken;
use App\Models\Permission;
use App\Models\ReadingAssignment;
use App\Models\ReadingCycle;
use App\Models\ReadingEntrySetting;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnusualConsumptionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $dataEntry;

    private User $accountant;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday 24 Sep 2026: its reading week runs Fri 18 Sep to Thu 24 Sep.
        $this->travelTo('2026-09-24 10:00:00');

        $this->branch = Branch::factory()->create();
        $this->dataEntry = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]);
        $this->accountant = User::factory()->accountant()->create(['branch_id' => $this->branch->id]);
        $this->subscription = Subscription::factory()->create(['branch_id' => $this->branch->id, 'initial_reading' => 1000]);
    }

    public function test_a_reading_no_subscription_could_use_in_a_week_is_refused_on_the_website(): void
    {
        $this->actingAs($this->dataEntry)->post(route('meter-readings.store'), $this->payload(['current_reading' => 1000000]))
            ->assertSessionHasErrors('current_reading');

        $this->assertDatabaseCount('meter_readings', 0);
    }

    public function test_the_largest_reading_that_is_still_possible_is_accepted_and_flagged_for_review(): void
    {
        $this->actingAs($this->dataEntry)->post(route('meter-readings.store'), $this->payload(['current_reading' => 21000]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'meter-reading-created-unusual');

        $reading = MeterReading::sole();
        $this->assertSame(20000.0, $reading->consumption);
        $this->assertTrue($reading->isUnusual());
        $this->assertSame(0.0, $reading->usual_consumption);
    }

    public function test_a_new_subscription_with_no_history_is_flagged_only_from_a_very_large_week(): void
    {
        $this->actingAs($this->dataEntry)->post(route('meter-readings.store'), $this->payload(['current_reading' => 2999]))
            ->assertSessionHas('status', 'meter-reading-created');
        MeterReading::query()->delete();

        $this->post(route('meter-readings.store'), $this->payload(['current_reading' => 3000]))
            ->assertSessionHas('status', 'meter-reading-created-unusual');
    }

    public function test_the_limit_is_the_one_in_the_configuration(): void
    {
        config(['powercollect.readings.max_weekly_kwh' => 500]);

        $this->actingAs($this->dataEntry)->post(route('meter-readings.store'), $this->payload(['current_reading' => 1501]))
            ->assertSessionHasErrors('current_reading');
        $this->post(route('meter-readings.store'), $this->payload(['current_reading' => 1500]))
            ->assertSessionHasNoErrors();
    }

    public function test_a_reading_far_above_the_subscriptions_usual_is_saved_but_flagged_with_what_is_usual(): void
    {
        $this->history(consumptions: [40, 60, 50, 50]);

        $this->actingAs($this->dataEntry)->post(route('meter-readings.store'), $this->payload(['current_reading' => $this->nextPrevious() + 300]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'meter-reading-created-unusual');

        $reading = MeterReading::query()->latest('id')->first();
        $this->assertSame(MeterReadingStatus::Pending, $reading->status);
        $this->assertTrue($reading->isUnusual());
        $this->assertSame(50.0, $reading->usual_consumption);
    }

    public function test_an_ordinary_reading_is_not_flagged(): void
    {
        $this->history(consumptions: [40, 60, 50, 50]);

        $this->actingAs($this->dataEntry)->post(route('meter-readings.store'), $this->payload(['current_reading' => $this->nextPrevious() + 90]))
            ->assertSessionHas('status', 'meter-reading-created');

        $this->assertFalse(MeterReading::query()->latest('id')->first()->isUnusual());
    }

    public function test_a_small_subscription_is_not_flagged_for_a_rise_that_is_still_small(): void
    {
        $this->history(consumptions: [10, 10, 10, 10]);

        // Eight times the usual, but only 80 kWh.
        $this->actingAs($this->dataEntry)->post(route('meter-readings.store'), $this->payload(['current_reading' => $this->nextPrevious() + 80]))
            ->assertSessionHas('status', 'meter-reading-created');
    }

    public function test_a_subscription_with_little_history_is_not_flagged_by_comparison(): void
    {
        $this->history(consumptions: [10, 10]);

        $this->actingAs($this->dataEntry)->post(route('meter-readings.store'), $this->payload(['current_reading' => $this->nextPrevious() + 900]))
            ->assertSessionHas('status', 'meter-reading-created');
    }

    public function test_only_the_latest_eight_readings_make_up_the_usual(): void
    {
        // Oldest first: four huge weeks, then eight ordinary ones.
        $this->history(consumptions: [5000, 5000, 5000, 5000, 50, 50, 50, 50, 50, 50, 50, 50]);

        $this->actingAs($this->dataEntry)->post(route('meter-readings.store'), $this->payload(['current_reading' => $this->nextPrevious() + 400]))
            ->assertSessionHas('status', 'meter-reading-created-unusual');

        $this->assertSame(50.0, MeterReading::query()->latest('id')->first()->usual_consumption);
    }

    public function test_the_app_refuses_an_impossible_reading_and_says_when_one_is_flagged(): void
    {
        ReadingEntrySetting::factory()->forcedOpen()->create();
        $this->history(consumptions: [50, 50, 50, 50]);
        $user = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]);
        ReadingAssignment::factory()->mobile($user)->approved()->create([
            'reading_cycle_id' => ReadingCycle::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->accountant->id])->id,
            'meter_box_id' => $this->subscription->meter_box_id, 'assigned_by' => $this->accountant->id,
        ]);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($user));
        $week = MeterReading::latestEndedWeekStart(null)->toDateString();
        $payload = fn (float $reading) => ['mobile_operation_id' => Str::uuid()->toString(), 'subscription_id' => $this->subscription->id, 'week_start' => $week, 'current_reading' => $reading];

        $this->postJson(route('mobile.readings.store'), $payload(999999))->assertUnprocessable()->assertJsonValidationErrors('current_reading');
        $this->assertSame(4, MeterReading::count());

        $this->postJson(route('mobile.readings.store'), $payload($this->nextPrevious() + 400))
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('unusual_consumption', true);
    }

    public function test_the_app_reports_an_ordinary_reading_as_not_unusual(): void
    {
        ReadingEntrySetting::factory()->forcedOpen()->create();
        $user = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]);
        ReadingAssignment::factory()->mobile($user)->approved()->create([
            'reading_cycle_id' => ReadingCycle::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->accountant->id])->id,
            'meter_box_id' => $this->subscription->meter_box_id, 'assigned_by' => $this->accountant->id,
        ]);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($user));

        $this->postJson(route('mobile.readings.store'), [
            'mobile_operation_id' => Str::uuid()->toString(),
            'subscription_id' => $this->subscription->id,
            'week_start' => MeterReading::latestEndedWeekStart(null)->toDateString(),
            'current_reading' => 1050,
        ])->assertCreated()->assertJsonPath('unusual_consumption', false);
    }

    public function test_correcting_a_reading_sets_or_clears_the_flag_and_refuses_an_impossible_one(): void
    {
        $this->history(consumptions: [50, 50, 50, 50]);
        $this->actingAs($this->dataEntry)->post(route('meter-readings.store'), $this->payload(['current_reading' => $this->nextPrevious() + 400]));
        $reading = MeterReading::query()->latest('id')->first();
        $this->assertTrue($reading->isUnusual());

        $this->put(route('meter-readings.update', $reading), ['current_reading' => $reading->previous_reading + 60])->assertSessionHasNoErrors();
        $this->assertFalse($reading->fresh()->isUnusual());

        $this->put(route('meter-readings.update', $reading), ['current_reading' => $reading->previous_reading + 500])->assertSessionHasNoErrors();
        $this->assertSame(50.0, $reading->fresh()->usual_consumption);

        $this->put(route('meter-readings.update', $reading), ['current_reading' => 5000000])->assertSessionHasErrors('current_reading');
        $this->assertSame($reading->previous_reading + 500, $reading->fresh()->current_reading);
    }

    public function test_approving_everything_approves_the_ordinary_readings_and_holds_back_the_unusual_ones(): void
    {
        $ordinary = $this->pendingReading('10.00');
        $unusual = $this->pendingReading('9000.00', unusual: 50);

        $this->actingAs($this->accountant)->from(route('meter-readings.index'))
            ->post(route('meter-readings.approve'), ['all' => true, 'week' => '2026-09-18'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'meter-readings-approved-partly');

        $this->assertSame(MeterReadingStatus::Approved, $ordinary->fresh()->status);
        $this->assertSame(MeterReadingStatus::Pending, $unusual->fresh()->status);
        $this->assertSame(1, SubscriptionTransaction::count());
    }

    public function test_confirming_does_not_make_approve_all_take_the_unusual_ones(): void
    {
        $unusual = $this->pendingReading('9000.00', unusual: 50);

        $this->actingAs($this->accountant)->post(route('meter-readings.approve'), ['all' => true, 'week' => '2026-09-18', 'confirm_unusual' => true])
            ->assertSessionHasErrors('reading_ids');

        $this->assertSame(MeterReadingStatus::Pending, $unusual->fresh()->status);
    }

    public function test_an_unusual_reading_ticked_without_confirming_is_not_approved(): void
    {
        $unusual = $this->pendingReading('9000.00', unusual: 50);

        $this->actingAs($this->accountant)->post(route('meter-readings.approve'), ['reading_ids' => [$unusual->id]])
            ->assertSessionHasErrors('reading_ids');

        $this->assertSame(MeterReadingStatus::Pending, $unusual->fresh()->status);
        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_an_unusual_reading_ticked_and_confirmed_is_approved_and_charged(): void
    {
        $unusual = $this->pendingReading('9000.00', unusual: 50);

        $this->actingAs($this->accountant)->post(route('meter-readings.approve'), ['reading_ids' => [$unusual->id], 'confirm_unusual' => true])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'meter-readings-approved');

        $this->assertSame(MeterReadingStatus::Approved, $unusual->fresh()->status);
        $this->assertSame('9000.00', SubscriptionTransaction::sole()->amount);
    }

    public function test_correcting_an_approved_reading_to_an_unusual_one_is_not_approved_again_at_once(): void
    {
        $this->history(consumptions: [50, 50, 50, 50]);
        $previous = $this->nextPrevious();
        $reading = MeterReading::factory()->approved()->create([
            'subscription_id' => $this->subscription->id,
            'week_start' => '2026-09-18',
            'week_end' => '2026-09-24',
            'previous_reading' => $previous,
            'current_reading' => $previous + 50,
            'consumption' => 50,
            'approved_by' => $this->accountant->id,
            'approved_at' => now(),
        ]);

        $this->accountant->permissions()->attach(Permission::idsFor([PermissionKey::CorrectMeterReadings]));
        $this->actingAs($this->accountant->fresh())->put(route('meter-readings.update', $reading), ['current_reading' => $previous + 600, 'approve' => true])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'meter-reading-reopened');

        $this->assertSame(MeterReadingStatus::Pending, $reading->fresh()->status);
        $this->assertTrue($reading->fresh()->isUnusual());
    }

    public function test_the_sheet_shows_which_readings_are_unusual_and_how_many_are_waiting(): void
    {
        $this->pendingReading('10.00');
        $unusual = $this->pendingReading('9000.00', unusual: 50);

        $this->actingAs($this->accountant)->get(route('meter-readings.index', ['week' => '2026-09-18']))->assertInertia(fn ($page) => $page
            ->where('pendingApproval.count', 2)
            ->where('pendingApproval.unusualCount', 1)
            ->where('rows.data', function ($rows) use ($unusual): bool {
                $row = collect($rows)->first(fn (array $row): bool => ($row['reading']['id'] ?? null) === $unusual->id);

                return $row['reading']['unusual'] == ['usual' => 50] && collect($rows)->whereNotNull('reading.unusual')->count() === 1;
            }));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return ['subscription_id' => $this->subscription->id, 'week_start' => '2026-09-22', ...$overrides];
    }

    /**
     * The subscription's earlier weekly readings, oldest first, ending the
     * week before the reading week (Fri 18 Sep), each starting from the
     * last one's reading.
     *
     * @param  array<int, float|int>  $consumptions
     */
    private function history(array $consumptions, ?MeterReading $before = null): void
    {
        $weekStart = Carbon::parse($before?->week_start ?? '2026-09-18')->subWeeks(count($consumptions));
        $reading = $this->subscription->initial_reading;

        foreach ($consumptions as $consumption) {
            MeterReading::factory()->approved()->create([
                'subscription_id' => $this->subscription->id,
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekStart->copy()->addDays(6)->toDateString(),
                'previous_reading' => $reading,
                'current_reading' => $reading + $consumption,
                'consumption' => $consumption,
            ]);
            $reading += $consumption;
            $weekStart = $weekStart->copy()->addWeek();
        }
    }

    /** The reading the next week starts from. */
    private function nextPrevious(): float
    {
        return (float) MeterReading::query()->where('subscription_id', $this->subscription->id)->orderByDesc('week_start')->value('current_reading');
    }

    private function pendingReading(string $amountDue, ?float $unusual = null): MeterReading
    {
        $subscription = Subscription::factory()->create(['branch_id' => $this->branch->id]);

        return MeterReading::factory()->create([
            'subscription_id' => $subscription->id,
            'week_start' => '2026-09-18',
            'week_end' => '2026-09-24',
            'amount_due' => $amountDue,
            'reading_fee' => $amountDue,
            'usual_consumption' => $unusual,
        ]);
    }
}
