<?php

namespace Tests\Feature\MeterReadings;

use App\Enums\MeterReadingStatus;
use App\Enums\PermissionKey;
use App\Enums\SubscriptionStatus;
use App\Models\Area;
use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\MeterReading;
use App\Models\Permission;
use App\Models\ReadingEntrySetting;
use App\Models\SubArea;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MeterReadingTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $dataEntry;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday 24 Sep 2026 — its reading week runs Fri 18 Sep → Thu 24 Sep.
        $this->travelTo('2026-09-24 10:00:00');

        $this->branch = Branch::factory()->create();
        $this->dataEntry = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]);
        $this->subscription = Subscription::factory()->create(['branch_id' => $this->branch->id, 'initial_reading' => 1200]);
    }

    public function test_data_entry_records_a_reading_without_charging_the_subscription(): void
    {
        $this->actingAs($this->dataEntry)
            ->from(route('subscriptions.index'))
            ->post(route('meter-readings.store'), [
                'subscription_id' => $this->subscription->id,
                'week_start' => '2026-09-22',
                'current_reading' => 1250,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'meter-reading-created')
            ->assertRedirect(route('subscriptions.index'));

        $reading = MeterReading::sole();
        $this->assertSame('2026-09-18', $reading->week_start->toDateString());
        $this->assertSame('2026-09-24', $reading->week_end->toDateString());
        $this->assertSame(1200.0, $reading->previous_reading);
        $this->assertSame(1250.0, $reading->current_reading);
        $this->assertSame(50.0, $reading->consumption);
        $this->assertSame(MeterReadingStatus::Pending, $reading->status);
        $this->assertSame($this->branch->id, $reading->branch_id);
        $this->assertTrue($reading->recordedBy->is($this->dataEntry));
        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_the_week_is_charged_consumption_times_the_kilowatt_price(): void
    {
        $this->subscription->tariff->update(['rate' => 0.6]);
        $this->subscription->update(['minimum_charge' => 20]);

        $this->actingAs($this->dataEntry)
            ->post(route('meter-readings.store'), $this->payload(['current_reading' => 1250]))
            ->assertSessionHasNoErrors();

        $reading = MeterReading::sole();
        $this->assertSame('0.60', $reading->unit_price);
        $this->assertSame('30.00', $reading->reading_fee);
        $this->assertSame('20.00', $reading->minimum_payment);
        $this->assertSame('30.00', $reading->amount_due);
        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_the_minimum_payment_is_charged_when_the_reading_costs_less(): void
    {
        $this->subscription->tariff->update(['rate' => 0.6]);
        $this->subscription->update(['minimum_charge' => 20]);

        $this->actingAs($this->dataEntry)
            ->post(route('meter-readings.store'), $this->payload(['current_reading' => 1218]))
            ->assertSessionHasNoErrors();

        $reading = MeterReading::sole();
        $this->assertSame('10.80', $reading->reading_fee);
        $this->assertSame('20.00', $reading->amount_due);
    }

    public function test_a_reading_can_have_two_decimal_places(): void
    {
        $this->subscription->update(['initial_reading' => 255.2]);
        $this->subscription->tariff->update(['rate' => 0.6]);
        $this->subscription->update(['minimum_charge' => 0]);

        $this->actingAs($this->dataEntry)
            ->post(route('meter-readings.store'), $this->payload(['current_reading' => '260.35']))
            ->assertSessionHasNoErrors();

        $reading = MeterReading::sole();
        $this->assertSame(255.2, $reading->previous_reading);
        $this->assertSame(260.35, $reading->current_reading);
        $this->assertSame(5.15, $reading->consumption);
        $this->assertSame('3.09', $reading->reading_fee);
    }

    public function test_a_reading_with_more_than_two_decimal_places_is_rejected(): void
    {
        $this->actingAs($this->dataEntry)
            ->post(route('meter-readings.store'), $this->payload(['current_reading' => '1250.555']))
            ->assertSessionHasErrors('current_reading');

        $this->assertDatabaseCount('meter_readings', 0);
    }

    public function test_correcting_a_reading_recalculates_its_charges_at_the_recorded_price(): void
    {
        $reading = $this->recordedReading('2026-09-18', 1200, 1250);
        $reading->update(['unit_price' => 0.5, 'minimum_payment' => 10]);
        $this->subscription->tariff->update(['rate' => 9]);

        $this->actingAs($this->dataEntry)
            ->put(route('meter-readings.update', $reading), ['current_reading' => 1260])
            ->assertSessionHasNoErrors();

        $reading->refresh();
        $this->assertSame('30.00', $reading->reading_fee);
        $this->assertSame('30.00', $reading->amount_due);
    }

    public function test_the_previous_reading_is_the_last_recorded_week(): void
    {
        $this->recordedReading('2026-09-11', 1200, 1300);

        $this->actingAs($this->dataEntry)
            ->post(route('meter-readings.store'), $this->payload(['current_reading' => 1340]))
            ->assertSessionHasNoErrors();

        $reading = MeterReading::whereDate('week_start', '2026-09-18')->sole();
        $this->assertSame(1300.0, $reading->previous_reading);
        $this->assertSame(40.0, $reading->consumption);
    }

    public function test_a_reading_cannot_be_lower_than_the_previous_reading(): void
    {
        $this->actingAs($this->dataEntry)
            ->post(route('meter-readings.store'), $this->payload(['current_reading' => 1199]))
            ->assertSessionHasErrors(['current_reading' => 'القراءة الحالية لا يمكن أن تكون أقل من القراءة السابقة (1200).']);

        $this->assertDatabaseCount('meter_readings', 0);
    }

    public function test_a_week_can_only_be_recorded_once_per_subscription(): void
    {
        $this->recordedReading('2026-09-18', 1200, 1250);

        $this->actingAs($this->dataEntry)
            ->post(route('meter-readings.store'), $this->payload(['current_reading' => 1260]))
            ->assertSessionHasErrors(['week_start' => 'تم تسجيل قراءة لهذا المشترك في هذا الأسبوع مسبقًا.']);

        $this->assertDatabaseCount('meter_readings', 1);
    }

    public function test_a_week_before_the_latest_recorded_week_is_rejected(): void
    {
        $this->recordedReading('2026-09-18', 1200, 1250);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('meter-readings.store'), $this->payload(['week_start' => '2026-09-11']))
            ->assertSessionHasErrors(['week_start' => 'يمكن إدخال قراءات الأسبوع الأخير فقط؛ الأسابيع السابقة للعرض فقط.']);
    }

    public function test_only_the_latest_week_can_be_entered_and_earlier_weeks_are_view_only(): void
    {
        $this->actingAs($this->dataEntry)
            ->post(route('meter-readings.store'), $this->payload(['week_start' => '2026-09-11']))
            ->assertSessionHasErrors(['week_start' => 'يمكن إدخال قراءات الأسبوع الأخير فقط؛ الأسابيع السابقة للعرض فقط.']);

        $this->assertDatabaseCount('meter_readings', 0);

        $this->actingAs($this->dataEntry)
            ->get(route('meter-readings.index', ['week' => '2026-09-11']))
            ->assertInertia(fn ($page) => $page
                ->where('canRecord', true)
                ->where('weekIsViewOnly', true)
                ->where('rows.data.0.canEdit', false));

        $this->actingAs($this->dataEntry)
            ->get(route('meter-readings.index'))
            ->assertInertia(fn ($page) => $page
                ->where('weekIsViewOnly', false)
                ->where('rows.data.0.canEdit', true));
    }

    public function test_the_super_admin_cannot_enter_a_missing_reading_for_an_earlier_week(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('meter-readings.store'), $this->payload(['week_start' => '2026-09-11']))
            ->assertSessionHasErrors(['week_start' => 'يمكن إدخال قراءات الأسبوع الأخير فقط؛ الأسابيع السابقة للعرض فقط.']);

        $this->assertDatabaseCount('meter_readings', 0);
        $this->get(route('meter-readings.index', ['week' => '2026-09-11']))
            ->assertInertia(fn ($page) => $page->where('weekIsViewOnly', true)->where('rows.data.0.canEdit', false));
    }

    public function test_the_sheet_stays_on_the_week_that_ended_on_thursday_when_entering_late(): void
    {
        $this->travelTo('2026-09-26 10:00:00'); // Saturday

        $this->actingAs($this->dataEntry)
            ->get(route('meter-readings.index'))
            ->assertInertia(fn ($page) => $page
                ->where('week', '2026-09-18')
                ->where('weekOptions.0.value', '2026-09-18')
                ->where('weekOptions.0.label', 'الأسبوع المنتهي في الخميس 24-09-2026')
                ->where('weekOptions.0.end', '2026-09-24')
                ->has('weekOptions', 60));

        $this->actingAs($this->dataEntry)
            ->get(route('meter-readings.index', ['week' => '2026-09-25']))
            ->assertInertia(fn ($page) => $page->where('week', '2026-09-18'));
    }

    public function test_a_week_that_has_not_ended_yet_is_rejected(): void
    {
        ReadingEntrySetting::factory()->forcedOpen()->create();
        $this->travelTo('2026-09-26 10:00:00'); // Saturday — the week 25 Sep → 1 Oct is still running

        $this->actingAs($this->dataEntry)
            ->post(route('meter-readings.store'), $this->payload(['week_start' => '2026-09-25']))
            ->assertSessionHasErrors(['week_start' => 'لا يمكن إدخال قراءة لأسبوع لم ينتهِ بعد.']);

        $this->assertDatabaseCount('meter_readings', 0);
    }

    public function test_the_latest_ended_week_follows_the_business_timezone(): void
    {
        config(['app.business_timezone' => 'Asia/Gaza']);

        // Wednesday 20:00 UTC is still Wednesday in Gaza: the 18 → 24 week hasn't ended.
        $this->assertSame('2026-09-11', MeterReading::latestEndedWeekStart(null, now()->parse('2026-09-23 20:00:00', 'UTC'))->toDateString());
        // Wednesday 22:30 UTC is already Thursday in Gaza: the 18 → 24 week ends today.
        $this->assertSame('2026-09-18', MeterReading::latestEndedWeekStart(null, now()->parse('2026-09-23 22:30:00', 'UTC'))->toDateString());
    }

    public function test_the_last_week_on_the_old_reading_day_stays_the_latest_until_a_week_ends_on_the_new_day(): void
    {
        $reading = $this->recordedReading('2026-09-18', 1200, 1250);
        $this->readingDayMovedFromThursdayTo(CarbonInterface::SATURDAY, firstWeekEnd: '2026-09-26');
        $this->travelTo('2026-09-25 10:00:00'); // Friday

        $this->actingAs($this->dataEntry)
            ->get(route('meter-readings.index'))
            ->assertInertia(fn ($page) => $page
                ->where('week', '2026-09-18')
                ->where('weekEnd', '2026-09-24')
                ->where('rows.data.0.reading.id', $reading->id)
                ->where('rows.data.0.canEdit', true));
    }

    public function test_the_first_week_on_a_later_reading_day_starts_the_day_after_the_last_week_on_the_old_day(): void
    {
        $this->recordedReading('2026-09-18', 1200, 1250);
        $this->readingDayMovedFromThursdayTo(CarbonInterface::SATURDAY, firstWeekEnd: '2026-09-26');
        $this->travelTo('2026-09-26 10:00:00'); // Saturday

        $this->actingAs($this->dataEntry)
            ->post(route('meter-readings.store'), $this->payload(['week_start' => '2026-09-25', 'current_reading' => 1270]))
            ->assertSessionHasNoErrors();

        $reading = MeterReading::latest('id')->first();
        $this->assertSame('2026-09-25', $reading->week_start->toDateString());
        $this->assertSame('2026-09-26', $reading->week_end->toDateString());
        $this->assertSame(1250.0, $reading->previous_reading);
        $this->assertSame(20.0, $reading->consumption);
    }

    public function test_the_week_list_keeps_the_weeks_read_on_the_old_reading_day(): void
    {
        $this->readingDayMovedFromThursdayTo(CarbonInterface::SATURDAY, firstWeekEnd: '2026-09-26');
        $this->travelTo('2026-10-03 10:00:00'); // Saturday

        $this->actingAs($this->dataEntry)
            ->get(route('meter-readings.index'))
            ->assertInertia(fn ($page) => $page
                ->where('week', '2026-09-27')
                ->where('weekOptions', fn ($options): bool => $options->take(4)->pluck('label', 'value')->all() === [
                    '2026-09-27' => 'الأسبوع المنتهي في السبت 03-10-2026',
                    '2026-09-25' => 'الأسبوع المنتهي في السبت 26-09-2026',
                    '2026-09-18' => 'الأسبوع المنتهي في الخميس 24-09-2026',
                    '2026-09-11' => 'الأسبوع المنتهي في الخميس 17-09-2026',
                ]));
    }

    public function test_the_first_week_on_an_earlier_reading_day_starts_the_day_after_the_last_week_on_the_old_day(): void
    {
        $this->readingDayMovedFromThursdayTo(CarbonInterface::TUESDAY, firstWeekEnd: '2026-09-29');

        // Monday: the first Tuesday week (25 → 29 Sep) hasn't ended yet.
        $this->assertSame('2026-09-18', MeterReading::latestEndedWeekStart(null, now()->parse('2026-09-28 10:00:00'))->toDateString());

        $firstWeek = MeterReading::latestEndedWeekStart(null, now()->parse('2026-09-29 10:00:00'));
        $this->assertSame('2026-09-25', $firstWeek->toDateString());
        $this->assertSame('2026-09-29', MeterReading::weekEndFor($firstWeek, null)->toDateString());

        // After it, weeks run Wednesday → Tuesday.
        $this->assertSame('2026-09-30', MeterReading::latestEndedWeekStart(null, now()->parse('2026-10-06 10:00:00'))->toDateString());
    }

    public function test_a_future_week_is_rejected(): void
    {
        $this->actingAs($this->dataEntry)
            ->post(route('meter-readings.store'), $this->payload(['week_start' => '2026-09-25']))
            ->assertSessionHasErrors('week_start');

        $this->assertDatabaseCount('meter_readings', 0);
    }

    public function test_readings_cannot_be_recorded_for_another_branch_or_an_inactive_subscription(): void
    {
        $otherBranchSubscription = Subscription::factory()->create();
        $suspendedSubscription = Subscription::factory()->create(['branch_id' => $this->branch->id, 'status' => SubscriptionStatus::Suspended]);

        foreach ([$otherBranchSubscription, $suspendedSubscription] as $subscription) {
            $this->actingAs($this->dataEntry)
                ->post(route('meter-readings.store'), $this->payload(['subscription_id' => $subscription->id]))
                ->assertSessionHasErrors('subscription_id');
        }

        $this->assertDatabaseCount('meter_readings', 0);
    }

    public function test_data_entry_cannot_record_readings_outside_the_open_days(): void
    {
        ReadingEntrySetting::factory()->create(['open_days' => [CarbonInterface::THURSDAY]]);
        $this->travelTo('2026-09-22 10:00:00'); // Tuesday

        $this->actingAs($this->dataEntry)
            ->post(route('meter-readings.store'), $this->payload())
            ->assertForbidden();

        $this->actingAs($this->dataEntry)
            ->get(route('meter-readings.index'))
            ->assertInertia(fn ($page) => $page
                ->where('canRecord', false)
                ->where('entryWindow.isOpen', false)
                ->where('entryWindow.appliesToActor', true)
                ->where('entryWindow.openDays', [CarbonInterface::THURSDAY])
                ->where('rows.data.0.canEdit', false));

        $this->assertDatabaseCount('meter_readings', 0);
    }

    #[TestWith(['2026-09-24 10:00:00', '2026-09-25'])]
    #[TestWith(['2026-09-25 05:29:00', '2026-09-25'])]
    #[TestWith(['2026-09-25 14:01:00', '2026-10-02'])]
    #[TestWith(['2026-09-26 10:00:00', '2026-10-02'])]
    #[TestWith(['2026-09-24 22:30:00', '2026-09-25'])]
    public function test_the_closed_sheet_shows_the_next_branch_reading_date_in_business_time(string $utcNow, string $expectedDate): void
    {
        config(['app.business_timezone' => 'Asia/Gaza']);
        ReadingEntrySetting::factory()->forBranch($this->branch)->create([
            'reading_day' => CarbonInterface::FRIDAY,
            'open_days' => [CarbonInterface::FRIDAY, CarbonInterface::SATURDAY],
            'opens_at' => '08:30:00', 'closes_at' => '17:00:00',
        ]);
        $this->travelTo(now()->parse($utcNow, 'UTC'));

        $this->actingAs($this->dataEntry)->get(route('meter-readings.index'))->assertInertia(fn ($page) => $page
            ->where('entryWindow.isOpen', false)
            ->where('entryWindow.nextOpensOn', $expectedDate)
            ->where('entryWindow.opensAt', '08:30')
            ->where('entryWindow.closesAt', '17:00'));
    }

    #[TestWith(['open'])]
    #[TestWith(['closed'])]
    #[TestWith(['automatic'])]
    public function test_the_notice_does_not_promise_an_opening_without_an_automatic_reading_day(string $mode): void
    {
        ReadingEntrySetting::factory()->forBranch($this->branch)->create([
            'mode' => $mode, 'reading_day' => CarbonInterface::FRIDAY,
            'open_days' => [CarbonInterface::SATURDAY],
        ]);

        $this->actingAs($this->dataEntry)->get(route('meter-readings.index'))->assertInertia(fn ($page) => $page
            ->where('entryWindow.nextOpensOn', null));
    }

    public function test_new_readings_obey_entry_hours_but_the_super_admin_can_record_outside_them(): void
    {
        config(['app.business_timezone' => 'Asia/Gaza']);
        ReadingEntrySetting::factory()->create(['opens_at' => '08:30:00', 'closes_at' => '17:00:00']);
        $this->travelTo(now()->parse('2026-09-24 05:29:00', 'UTC'));

        $this->actingAs($this->dataEntry)->post(route('meter-readings.store'), $this->payload())->assertForbidden();
        $this->get(route('meter-readings.index'))->assertInertia(fn ($page) => $page
            ->where('canRecord', false)
            ->where('entryWindow.opensAt', '08:30')
            ->where('entryWindow.closesAt', '17:00'));
        $this->assertDatabaseCount('meter_readings', 0);

        $this->travelTo(now()->parse('2026-09-24 05:30:00', 'UTC'));
        $this->post(route('meter-readings.store'), $this->payload())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('meter_readings', 1);

        $this->travelTo(now()->parse('2026-09-24 14:01:00', 'UTC'));
        $anotherSubscription = Subscription::factory()->create(['branch_id' => $this->branch->id, 'initial_reading' => 1200]);
        $this->post(route('meter-readings.store'), $this->payload(['subscription_id' => $anotherSubscription->id]))->assertForbidden();
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('meter-readings.store'), $this->payload(['subscription_id' => $anotherSubscription->id]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('meter_readings', 2);
    }

    public function test_the_latest_weeks_readings_can_still_be_corrected_while_entry_is_closed_but_not_added(): void
    {
        $reading = $this->recordedReading('2026-09-18', 1200, 1250);
        $missing = Subscription::factory()->create(['branch_id' => $this->branch->id]);
        ReadingEntrySetting::factory()->forcedClosed()->create();

        $this->actingAs($this->dataEntry)
            ->get(route('meter-readings.index'))
            ->assertInertia(fn ($page) => $page
                ->where('canRecord', false)
                ->where('rows.data', fn ($rows): bool => collect($rows)->pluck('canEdit', 'id')->all() == [
                    $this->subscription->id => true,
                    $missing->id => false,
                ]));

        $this->actingAs($this->dataEntry)
            ->from(route('meter-readings.index'))
            ->put(route('meter-readings.update', $reading), ['current_reading' => 1260])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->dataEntry)
            ->post(route('meter-readings.store'), $this->payload(['subscription_id' => $missing->id]))
            ->assertForbidden();

        $this->assertSame(1260.0, $reading->fresh()->current_reading);
        $this->assertDatabaseCount('meter_readings', 1);
    }

    public function test_the_manual_switch_opens_entry_on_any_day(): void
    {
        ReadingEntrySetting::factory()->forcedOpen()->create(['open_days' => [CarbonInterface::THURSDAY]]);
        $this->travelTo('2026-09-21 10:00:00'); // Monday — the latest ended week is 11 → 17 Sep

        $this->actingAs($this->dataEntry)
            ->post(route('meter-readings.store'), $this->payload(['week_start' => '2026-09-11']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('meter_readings', 1);
    }

    public function test_a_branch_admin_cannot_record_readings_while_entry_is_closed(): void
    {
        ReadingEntrySetting::factory()->forcedClosed()->create();
        $branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($branchAdmin)
            ->post(route('meter-readings.store'), $this->payload())
            ->assertForbidden();

        $this->actingAs($branchAdmin)
            ->get(route('meter-readings.index'))
            ->assertInertia(fn ($page) => $page
                ->where('entryWindow.appliesToActor', true)
                ->where('rows.data.0.canEdit', false));

        $this->assertDatabaseCount('meter_readings', 0);
    }

    public function test_the_super_admin_can_record_readings_while_entry_is_closed(): void
    {
        ReadingEntrySetting::factory()->forcedClosed()->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('meter-readings.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('meter_readings', 1);
    }

    public function test_a_collector_cannot_record_readings(): void
    {
        $collector = User::factory()->collector()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($collector)
            ->post(route('meter-readings.store'), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('meter_readings', 0);
    }

    public function test_the_reading_sheet_lists_active_subscriptions_of_the_actors_branch_with_their_last_reading(): void
    {
        $this->recordedReading('2026-09-11', 1200, 1250);
        Subscription::factory()->create(['branch_id' => $this->branch->id, 'status' => SubscriptionStatus::Suspended]);
        Subscription::factory()->create();

        $this->actingAs($this->dataEntry)
            ->get(route('meter-readings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MeterReadings/Index')
                ->where('week', '2026-09-18')
                ->has('rows.data', 1)
                ->where('rows.data.0.id', $this->subscription->id)
                ->where('rows.data.0.previousReading', 1250)
                ->where('rows.data.0.reading', null)
                ->where('rows.data.0.canEdit', true)
                ->where('summary.total', 1)
                ->where('summary.entered', 0));
    }

    public function test_subscription_status_filter_shows_inactive_subscriptions_without_allowing_new_readings(): void
    {
        $disconnected = Subscription::factory()->create(['branch_id' => $this->branch->id, 'status' => SubscriptionStatus::Disconnected]);
        Subscription::factory()->create(['status' => SubscriptionStatus::Disconnected]);

        $this->actingAs($this->dataEntry)->get(route('meter-readings.index', ['filter' => ['subscription_status' => 'disconnected']]))
            ->assertInertia(fn ($page) => $page->has('rows.data', 1)
                ->where('rows.data.0.id', $disconnected->id)->where('rows.data.0.canEdit', false)->where('summary.total', 1));
        $this->get(route('meter-readings.index', ['filter' => ['subscription_status' => 'all']]))
            ->assertInertia(fn ($page) => $page->has('rows.data', 2)->where('summary.total', 2));
    }

    public function test_reading_message_action_requires_both_approval_and_message_permissions(): void
    {
        $this->dataEntry->permissions()->sync(Permission::idsFor([PermissionKey::ViewMeterReadings, PermissionKey::ApproveMeterReadings]));
        $this->actingAs($this->dataEntry)->get(route('meter-readings.index'))
            ->assertInertia(fn ($page) => $page->where('canSendReadingMessages', false));
        $this->dataEntry->permissions()->attach(Permission::idsFor([PermissionKey::SendMessages]));
        $this->dataEntry->unsetRelation('permissions');
        $this->get(route('meter-readings.index'))
            ->assertInertia(fn ($page) => $page->where('canSendReadingMessages', true)->where('messageBranchId', $this->branch->id));
    }

    public function test_the_reading_sheet_passes_on_the_saved_status_for_the_success_message(): void
    {
        $this->actingAs($this->dataEntry)
            ->withSession(['status' => 'meter-reading-created'])
            ->get(route('meter-readings.index'))
            ->assertInertia(fn ($page) => $page->where('status', 'meter-reading-created'));
    }

    public function test_the_current_reading_stays_until_the_next_reading_day_then_becomes_previous(): void
    {
        config(['app.business_timezone' => 'Asia/Gaza']);
        $this->recordedReading('2026-09-11', 1200, 1250);

        $this->actingAs($this->dataEntry)
            ->post(route('meter-readings.store'), $this->payload(['current_reading' => 1300]))
            ->assertSessionHasNoErrors();

        $this->get(route('meter-readings.index'))->assertInertia(fn ($page) => $page
            ->where('weekEnd', '2026-09-24')
            ->where('rows.data.0.previousReading', 1250)
            ->where('rows.data.0.reading.currentReading', 1300));

        $this->travelTo(now()->parse('2026-09-30 23:59:59', 'Asia/Gaza'));
        $this->get(route('meter-readings.index', ['sort' => 'current_reading']))->assertInertia(fn ($page) => $page
            ->where('weekEnd', '2026-09-24')
            ->where('rows.data.0.previousReading', 1250)
            ->where('rows.data.0.reading.currentReading', 1300)
            ->where('canRecord', false));

        $this->travelTo(now()->parse('2026-10-01 00:00:00', 'Asia/Gaza'));
        $this->get(route('meter-readings.index', ['sort' => 'current_reading']))->assertInertia(fn ($page) => $page
            ->where('week', '2026-09-25')
            ->where('weekEnd', '2026-10-01')
            ->where('rows.data.0.previousReading', 1300)
            ->where('rows.data.0.reading', null)
            ->where('rows.data.0.canEdit', true)
            ->where('summary.entered', 0));

        $this->get(route('meter-readings.index', ['week' => '2026-09-18']))->assertInertia(fn ($page) => $page
            ->where('weekEnd', '2026-09-24')
            ->where('rows.data.0.previousReading', 1250)
            ->where('rows.data.0.reading.currentReading', 1300));

        $this->assertDatabaseCount('meter_readings', 2);
    }

    public function test_extra_open_days_do_not_allow_new_website_entries_but_keep_correction_permissions(): void
    {
        ReadingEntrySetting::factory()->forBranch($this->branch)->create([
            'reading_day' => CarbonInterface::THURSDAY,
            'open_days' => [CarbonInterface::THURSDAY, CarbonInterface::FRIDAY],
        ]);
        $reading = $this->recordedReading('2026-09-18', 1200, 1250);
        $missing = Subscription::factory()->create(['branch_id' => $this->branch->id]);
        $this->travelTo('2026-09-25 10:00:00');

        $this->assertFalse($this->dataEntry->can('createOnReadingDay', MeterReading::class));
        $this->assertTrue($this->dataEntry->can('update', $reading));
        $this->actingAs($this->dataEntry)->get(route('meter-readings.index'))->assertInertia(fn ($page) => $page
            ->where('canRecord', false)
            ->where('entryWindow.isOpen', false)
            ->where('entryWindow.openDays', [CarbonInterface::THURSDAY])
            ->where('rows.data', fn ($rows): bool => collect($rows)->pluck('canEdit', 'id')->all() == [
                $this->subscription->id => true, $missing->id => false,
            ]));
        $this->post(route('meter-readings.store'), $this->payload(['subscription_id' => $missing->id]))->assertForbidden();
        $this->put(route('meter-readings.update', $reading), ['current_reading' => 1260])->assertSessionHasNoErrors();

        $this->assertSame(1260.0, $reading->fresh()->current_reading);
        $this->assertDatabaseCount('meter_readings', 1);
    }

    public function test_the_super_admin_keeps_the_existing_entry_exception_outside_the_reading_day(): void
    {
        $this->travelTo('2026-09-25 10:00:00');
        $actor = User::factory()->superAdmin()->create();

        $this->assertTrue($actor->can('createOnReadingDay', MeterReading::class));
        $this->actingAs($actor)->post(route('meter-readings.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertDatabaseHas('meter_readings', ['subscription_id' => $this->subscription->id, 'current_reading' => 1250]);
        $this->assertSame('2026-09-18', MeterReading::sole()->week_start->toDateString());
    }

    public function test_the_reading_sheet_filters_by_box_name_and_lists_that_names_boxes_under_it(): void
    {
        $campOne = MeterBox::factory()->create(['branch_id' => $this->branch->id, 'name' => 'camp', 'name_suffix' => '1', 'box_number' => '1234']);
        $campTwo = MeterBox::factory()->create(['branch_id' => $this->branch->id, 'name' => 'camp', 'name_suffix' => '2', 'box_number' => '1243']);
        $inCampOne = Subscription::factory()->create(['branch_id' => $this->branch->id, 'meter_box_id' => $campOne->id]);
        $inCampTwo = Subscription::factory()->create(['branch_id' => $this->branch->id, 'meter_box_id' => $campTwo->id]);

        $this->actingAs($this->dataEntry)
            ->get(route('meter-readings.index', ['filter' => ['meter_box_name' => 'camp']]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 2)
                ->where('rows.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === [$inCampOne->id, $inCampTwo->id])
                ->where('filterOptions', fn ($groups) => collect(collect($groups)->firstWhere('key', 'meter_box_id')['options'])
                    ->where('parent', 'camp')->pluck('label')->all() === ['1 (1234)', '2 (1243)']));

        $this->actingAs($this->dataEntry)
            ->get(route('meter-readings.index', ['filter' => ['meter_box_name' => 'camp', 'meter_box_id' => $campOne->id]]))
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 1)
                ->where('rows.data.0.id', $inCampOne->id));
    }

    public function test_the_reading_sheets_filters_name_the_branch_area_and_sub_area_each_option_belongs_to(): void
    {
        $area = Area::factory()->create();
        $otherArea = Area::factory()->create();
        $north = Branch::factory()->create(['area_id' => $area->id]);
        $south = Branch::factory()->create(['area_id' => $otherArea->id]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $box = MeterBox::factory()->create(['branch_id' => $north->id, 'sub_area_id' => $subArea->id, 'name' => 'camp']);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('meter-readings.index', ['filter' => ['meter_box_name' => 'camp']]))
            ->assertInertia(fn ($page) => $page->where('filterOptions', function ($groups) use ($area, $north, $south, $subArea, $box): bool {
                $option = fn (string $key, int|string $value): array => collect(collect($groups)->firstWhere('key', $key)['options'])->firstWhere('value', (string) $value);

                return $option('area_id', $area->id)['scope']['branch_id'] === [(string) $north->id]
                    && ! in_array((string) $south->id, $option('area_id', $area->id)['scope']['branch_id'], true)
                    && $option('sub_area_id', $subArea->id)['scope'] === ['area_id' => (string) $area->id, 'branch_id' => [(string) $north->id]]
                    && $option('meter_box_id', $box->id)['scope'] === ['branch_id' => (string) $north->id, 'sub_area_id' => (string) $subArea->id, 'area_id' => (string) $area->id]
                    && $option('meter_box_name', 'camp')['scope']['branch_id'] === [(string) $north->id];
            }));
    }

    public function test_the_reading_sheet_can_show_only_subscriptions_still_missing_this_weeks_reading(): void
    {
        $this->recordedReading('2026-09-18', 1200, 1250);
        $missing = Subscription::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->dataEntry)
            ->get(route('meter-readings.index', ['filter' => ['entry' => 'missing']]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 1)
                ->where('rows.data.0.id', $missing->id)
                ->where('summary.entered', 1));
    }

    public function test_the_reading_sheet_sorts_by_the_weeks_computed_reading_columns(): void
    {
        $this->recordedReading('2026-09-18', 1200, 1290);
        $lowUsage = Subscription::factory()->create(['branch_id' => $this->branch->id, 'initial_reading' => 5000, 'full_name' => 'Aaa']);
        MeterReading::factory()->create([
            'subscription_id' => $lowUsage->id,
            'week_start' => '2026-09-18',
            'week_end' => '2026-09-24',
            'previous_reading' => 5000,
            'current_reading' => 5010,
            'consumption' => 10,
        ]);
        $notEntered = Subscription::factory()->create(['branch_id' => $this->branch->id, 'initial_reading' => 300, 'full_name' => 'Zzz']);

        $sortedIds = fn (string $sort, string $direction) => $this->actingAs($this->dataEntry)
            ->get(route('meter-readings.index', ['sort' => $sort, 'direction' => $direction]))
            ->assertOk()
            ->viewData('page')['props']['rows']['data'];

        $this->assertSame(
            [$this->subscription->id, $lowUsage->id],
            array_slice(array_column($sortedIds('consumption', 'desc'), 'id'), 0, 2),
        );
        $this->assertSame(
            [$notEntered->id, $this->subscription->id, $lowUsage->id],
            array_column($sortedIds('last_reading', 'asc'), 'id'),
        );
    }

    public function test_the_reading_sheet_sorts_by_meter_box_name_then_suffix_then_number(): void
    {
        $box = fn (string $name, ?string $suffix, string $number) => MeterBox::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => $name,
            'name_suffix' => $suffix,
            'box_number' => $number,
        ])->id;
        $this->subscription->update(['meter_box_id' => $box('Camp', '2', 'BOX-1')]);
        $campTen = Subscription::factory()->create(['branch_id' => $this->branch->id, 'meter_box_id' => $box('Camp', null, 'BOX-10')]);
        $campNine = Subscription::factory()->create(['branch_id' => $this->branch->id, 'meter_box_id' => $box('Camp', null, 'BOX-9')]);
        $alley = Subscription::factory()->create(['branch_id' => $this->branch->id, 'meter_box_id' => $box('Alley', null, 'BOX-50')]);
        $noBox = Subscription::factory()->create(['branch_id' => $this->branch->id, 'meter_box_id' => null]);

        $rows = $this->actingAs($this->dataEntry)
            ->get(route('meter-readings.index', ['sort' => 'meter_box', 'direction' => 'asc']))
            ->assertOk()
            ->viewData('page')['props']['rows']['data'];

        $this->assertSame([$alley->id, $campNine->id, $campTen->id, $this->subscription->id, $noBox->id], array_column($rows, 'id'));
        $this->assertSame('Camp 2', $rows[3]['meterBoxName']);
        $this->assertSame($this->subscription->contactPhone(), $rows[3]['phone']);
    }

    public function test_the_reading_sheet_filters_by_the_meter_boxs_sub_area(): void
    {
        $subArea = SubArea::factory()->create();
        $inSubArea = Subscription::factory()->create([
            'branch_id' => $this->branch->id,
            'meter_box_id' => MeterBox::factory()->create(['branch_id' => $this->branch->id, 'sub_area_id' => $subArea->id])->id,
        ]);

        $this->actingAs($this->dataEntry)
            ->get(route('meter-readings.index', ['filter' => ['sub_area_id' => $subArea->id]]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 1)
                ->where('rows.data.0.id', $inSubArea->id));
    }

    public function test_the_subscription_statement_includes_their_readings(): void
    {
        $this->recordedReading('2026-09-11', 1200, 1250, MeterReadingStatus::Approved)
            ->update(['reading_fee' => 150, 'minimum_payment' => 20]);
        $this->recordedReading('2026-09-18', 1250, 1290)
            ->update(['mobile_operation_id' => '12345678-1234-4123-8123-123456789012', 'reading_fee' => 10, 'minimum_payment' => 20]);

        $this->actingAs($this->dataEntry)
            ->get(route('subscriptions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('subscriptions.data.0.id', $this->subscription->id)
                ->where('subscriptions.data.0.lastReading', 1290)
                ->where('subscriptions.data.0.lastReadingWeekStart', '2026-09-18')
                ->where('subscriptions.data.0.canRecordReading', true)
                ->has('subscriptions.data.0.meterReadings', 2)
                ->where('subscriptions.data.0.meterReadings.0.weekStart', '2026-09-18')
                ->where('subscriptions.data.0.meterReadings.0.consumption', 40)
                ->where('subscriptions.data.0.meterReadings.0.discountAmount', '0.00')
                ->where('subscriptions.data.0.meterReadings.0.minimumPayment', '20.00')
                ->has('subscriptions.data.0.meterReadings.0.unitPrice')
                ->where('subscriptions.data.0.meterReadings.0.recordedSource', 'app')
                ->where('subscriptions.data.0.meterReadings.0.minimumApplied', true)
                ->where('subscriptions.data.0.meterReadings.1.recordedSource', 'web')
                ->where('subscriptions.data.0.meterReadings.1.minimumApplied', false)
                ->where('subscriptions.data.0.meterReadings.1.status', 'approved')
                ->where('subscriptions.data.0.meterReadings.0.canUpdate', true)
                ->where('subscriptions.data.0.meterReadings.1.canUpdate', false)
                ->has('readingWeekOptions', 1)
                ->where('readingWeekOptions.'.$this->dataEntry->branch_id.'.0.value', '2026-09-18'));
    }

    public function test_the_history_does_not_label_a_discounted_reading_as_a_minimum_charge(): void
    {
        $this->recordedReading('2026-09-18', 1200, 1201)->update([
            'reading_fee' => 3,
            'minimum_payment' => 20,
            'discount_method' => 'percentage',
            'discount_value' => 10,
            'discount_amount' => 0.3,
            'amount_due' => 2.7,
        ]);

        $this->actingAs($this->dataEntry)
            ->get(route('subscriptions.index'))
            ->assertInertia(fn ($page) => $page
                ->where('subscriptions.data.0.meterReadings.0.minimumApplied', false)
                ->where('subscriptions.data.0.meterReadings.0.amountDue', '2.70'));
    }

    public function test_a_collector_is_not_offered_reading_entry_on_the_statement(): void
    {
        $collector = User::factory()->collector()->create(['branch_id' => $this->branch->id]);
        $collector->permissions()->attach(
            Permission::firstOrCreate(
                ['key' => PermissionKey::ViewSubscriptions->value],
                ['label' => PermissionKey::ViewSubscriptions->label()],
            ),
        );

        $this->actingAs($collector)
            ->get(route('subscriptions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('subscriptions.data.0.canRecordReading', false));
    }

    public function test_staff_who_only_enter_readings_do_not_see_who_recorded_them(): void
    {
        $this->recordedReading('2026-09-18', 1200, 1250);

        $this->actingAs($this->dataEntry)
            ->get(route('meter-readings.index'))
            ->assertInertia(fn ($page) => $page
                ->where('seesWhoRecorded', false)
                ->where('rows.data.0.reading.consumption', 50)
                ->missing('rows.data.0.reading.recordedByName')
                ->missing('rows.data.0.reading.approvedByName'));
    }

    public function test_whoever_may_read_the_audit_log_sees_who_recorded_each_reading(): void
    {
        $reading = $this->recordedReading('2026-09-18', 1200, 1250);
        $auditor = User::factory()->dataEntry()->withPermissions([PermissionKey::ViewTransactionAudit])->create(['branch_id' => $this->branch->id]);

        $this->actingAs($auditor)
            ->get(route('meter-readings.index'))
            ->assertInertia(fn ($page) => $page
                ->where('seesWhoRecorded', true)
                ->where('rows.data.0.reading.recordedByName', $reading->recordedBy->name));
    }

    public function test_a_pending_reading_can_be_corrected(): void
    {
        $reading = $this->recordedReading('2026-09-18', 1200, 1250);

        $this->actingAs($this->dataEntry)
            ->from(route('meter-readings.index'))
            ->put(route('meter-readings.update', $reading), ['current_reading' => 1235, 'notes' => 'تصحيح'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('meter-readings.index'));

        $reading->refresh();
        $this->assertSame(1235.0, $reading->current_reading);
        $this->assertSame(35.0, $reading->consumption);
        $this->assertSame('تصحيح', $reading->notes);
    }

    public function test_an_approved_reading_of_an_earlier_week_cannot_be_corrected(): void
    {
        // Two weeks before the latest: older than the last reading, which stays editable (see LastReadingTest).
        $reading = $this->recordedReading('2026-09-04', 1200, 1250, MeterReadingStatus::Approved);

        $this->actingAs($this->dataEntry)
            ->put(route('meter-readings.update', $reading), ['current_reading' => 1235])
            ->assertForbidden();

        $this->assertSame(1250.0, $reading->fresh()->current_reading);
    }

    public function test_a_reading_cannot_be_corrected_once_a_later_week_exists(): void
    {
        $earlier = $this->recordedReading('2026-09-04', 1200, 1250);
        $this->recordedReading('2026-09-11', 1250, 1280);
        $this->recordedReading('2026-09-18', 1280, 1300);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->put(route('meter-readings.update', $earlier), ['current_reading' => 1260])
            ->assertForbidden();

        $this->assertSame(1250.0, $earlier->fresh()->current_reading);
    }

    public function test_a_reading_from_an_earlier_week_cannot_be_corrected_even_by_a_branch_admin(): void
    {
        $reading = $this->recordedReading('2026-09-04', 1200, 1250);
        $branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($branchAdmin)
            ->put(route('meter-readings.update', $reading), ['current_reading' => 1260])
            ->assertForbidden();

        $this->assertSame(1250.0, $reading->fresh()->current_reading);
    }

    public function test_the_super_admin_can_correct_only_the_immediately_previous_week_as_the_last_reading(): void
    {
        $reading = $this->recordedReading('2026-09-11', 1200, 1250);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->put(route('meter-readings.update', $reading), ['current_reading' => 1260])
            ->assertSessionHasNoErrors();

        $this->assertSame(1260.0, $reading->fresh()->current_reading);
    }

    public function test_another_branchs_reading_cannot_be_corrected(): void
    {
        $reading = MeterReading::factory()->create();

        $this->actingAs($this->dataEntry)
            ->put(route('meter-readings.update', $reading), ['current_reading' => 999999])
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'subscription_id' => $this->subscription->id,
            'week_start' => '2026-09-18',
            'current_reading' => 1250,
            ...$overrides,
        ];
    }

    /**
     * The reading day moved away from Thursday after the 18 → 24 Sep week
     * ended, the first week on the new day ending on `$firstWeekEnd`. Entry
     * is left open so only the weeks decide what can be entered.
     */
    private function readingDayMovedFromThursdayTo(int $readingDay, string $firstWeekEnd): void
    {
        ReadingEntrySetting::factory()->forcedOpen()->create([
            'reading_day' => $readingDay,
            'reading_day_history' => [['reading_day' => CarbonInterface::THURSDAY, 'last_week_end' => '2026-09-24', 'next_week_end' => $firstWeekEnd]],
        ]);
    }

    private function recordedReading(string $weekStart, int $previous, int $current, MeterReadingStatus $status = MeterReadingStatus::Pending): MeterReading
    {
        return MeterReading::factory()->create([
            'subscription_id' => $this->subscription->id,
            'week_start' => $weekStart,
            'week_end' => now()->parse($weekStart)->addDays(6),
            'previous_reading' => $previous,
            'current_reading' => $current,
            'consumption' => $current - $previous,
            'status' => $status,
        ]);
    }
}
