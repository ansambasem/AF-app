<?php

namespace Tests\Feature\MeterReadings;

use App\Enums\MeterReadingStatus;
use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\MeterReading;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sheet's last reading — the reading of the week before the latest one —
 * can be edited by whoever holds the permission for it, and the latest week,
 * once entered, is recalculated from it.
 */
class LastReadingTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $dataEntry;

    private User $accountant;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday 24 Sep 2026: the latest week runs 18 → 24 Sep, the one before it 11 → 17 Sep.
        $this->travelTo('2026-09-24 10:00:00');

        $this->branch = Branch::factory()->create();
        $this->dataEntry = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id, 'name' => 'Elenora']);
        $this->accountant = User::factory()->accountant()->create(['branch_id' => $this->branch->id]);
        $this->subscription = Subscription::factory()->create(['branch_id' => $this->branch->id, 'full_name' => 'Ahmad']);
    }

    public function test_data_entry_edits_last_weeks_reading_and_this_weeks_reading_starts_from_it(): void
    {
        $lastWeek = $this->reading('2026-09-11', 1200, 1250);
        $thisWeek = $this->reading('2026-09-18', 1250, 1300);

        $this->actingAs($this->dataEntry)
            ->put(route('meter-readings.update', $lastWeek), ['current_reading' => 1240])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'meter-reading-last-updated');

        $lastWeek->refresh();
        $this->assertSame([1240.0, 40.0, '20.00'], [$lastWeek->current_reading, $lastWeek->consumption, $lastWeek->amount_due]);

        $thisWeek->refresh();
        $this->assertSame([1240.0, 1300.0, 60.0, '30.00'], [$thisWeek->previous_reading, $thisWeek->current_reading, $thisWeek->consumption, $thisWeek->amount_due]);
    }

    public function test_editing_approved_readings_sends_both_weeks_back_to_review_and_reverses_their_bills(): void
    {
        $lastWeek = $this->reading('2026-09-11', 1200, 1250);
        $thisWeek = $this->reading('2026-09-18', 1250, 1300);
        $lastWeek->approve($this->accountant);
        $thisWeek->approve($this->accountant);
        $this->assertSame(50.0, $this->subscription->balance());

        $this->actingAs($this->dataEntry)
            ->put(route('meter-readings.update', $lastWeek), ['current_reading' => 1260])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'meter-reading-reopened');

        $this->assertSame(MeterReadingStatus::Pending, $lastWeek->refresh()->status);
        $this->assertSame(MeterReadingStatus::Pending, $thisWeek->refresh()->status);
        $this->assertSame(0.0, $this->subscription->balance());
        $this->assertSame(2, SubscriptionTransaction::where('type', SubscriptionTransaction::TYPE_METER_READING)->whereNotNull('cancelled_at')->count());
        $this->assertSame(
            ['action' => 'meter-reading-needs-reapproval', 'subject' => 'Ahmad — عدّلها Elenora'],
            $this->accountant->notifications()->sole()->data,
        );

        // Approved again, the two weeks are billed at the corrected amounts: 30 and 20 kWh.
        $lastWeek->approve($this->accountant);
        $thisWeek->approve($this->accountant);
        $this->assertSame(['30.00', '20.00'], [$lastWeek->amount_due, $thisWeek->amount_due]);
        $this->assertSame(50.0, $this->subscription->balance());
    }

    public function test_confirming_an_edited_last_reading_approves_both_weeks_again_at_once_in_week_order(): void
    {
        $lastWeek = $this->reading('2026-09-11', 1200, 1250);
        $thisWeek = $this->reading('2026-09-18', 1250, 1300);
        $lastWeek->approve($this->accountant);
        $thisWeek->approve($this->accountant);

        $this->actingAs($this->dataEntry)
            ->put(route('meter-readings.update', $lastWeek), ['current_reading' => 1240, 'next_current_reading' => 1300, 'as_last_reading' => true])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'meter-reading-last-corrected-approved');

        foreach ([$lastWeek->refresh(), $thisWeek->refresh()] as $reading) {
            $this->assertSame([MeterReadingStatus::Approved, $this->dataEntry->id], [$reading->status, $reading->approved_by]);
        }
        $this->assertSame(['20.00', '30.00'], [$lastWeek->amount_due, $thisWeek->amount_due]);
        $this->assertSame(50.0, $this->subscription->balance());
        $this->assertSame(0, $this->accountant->notifications()->count());

        // Recorded in order: both originals cancelled where they were, then their reversals, then last week's new charge before this week's.
        $lines = SubscriptionTransaction::query()->orderBy('id')->get();
        $this->assertSame(
            [['meter_reading', $lastWeek->id, true], ['meter_reading', $thisWeek->id, true], ['reversal', null, false], ['reversal', null, false], ['meter_reading', $lastWeek->id, false], ['meter_reading', $thisWeek->id, false]],
            $lines->map(fn (SubscriptionTransaction $line): array => [$line->type, $line->meter_reading_id, $line->isCancelled()])->all(),
        );
        // A cancelled charge still says the consumption it was billed for, not the corrected one.
        $this->assertSame(['50 كيلو', '40 كيلو'], [str($lines[0]->description())->afterLast('· ')->value(), str($lines[4]->description())->afterLast('· ')->value()]);
    }

    public function test_an_edit_that_makes_an_approved_week_unusual_saves_nothing_until_it_is_confirmed(): void
    {
        $lastWeek = $this->reading('2026-09-11', 1200, 1250);
        $thisWeek = $this->reading('2026-09-18', 1250, 3400);
        $lastWeek->approve($this->accountant);
        $thisWeek->approve($this->accountant, confirmUnusual: true);
        $balance = $this->subscription->balance();

        $this->actingAs($this->dataEntry)
            ->put(route('meter-readings.update', $lastWeek), ['current_reading' => 3300, 'next_current_reading' => 3400, 'as_last_reading' => true])
            ->assertSessionHasErrors('confirm_unusual');

        $this->assertSame([1250.0, MeterReadingStatus::Approved], [$lastWeek->fresh()->current_reading, $lastWeek->fresh()->status]);
        $this->assertSame([1250.0, MeterReadingStatus::Approved], [$thisWeek->fresh()->previous_reading, $thisWeek->fresh()->status]);
        $this->assertSame($balance, $this->subscription->balance());

        $this->put(route('meter-readings.update', $lastWeek), ['current_reading' => 3300, 'next_current_reading' => 3400, 'as_last_reading' => true, 'confirm_unusual' => true])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'meter-reading-last-corrected-approved');

        $this->assertSame([MeterReadingStatus::Approved, MeterReadingStatus::Approved], [$lastWeek->fresh()->status, $thisWeek->fresh()->status]);
        $this->assertSame(1050.0 + 50.0, $this->subscription->balance());
    }

    public function test_a_week_still_waiting_for_approval_keeps_waiting_after_the_edit(): void
    {
        $lastWeek = $this->reading('2026-09-11', 1200, 1250);
        $thisWeek = $this->reading('2026-09-18', 1250, 1300);
        $lastWeek->approve($this->accountant);

        $this->actingAs($this->dataEntry)
            ->put(route('meter-readings.update', $lastWeek), ['current_reading' => 1240, 'next_current_reading' => 1300, 'as_last_reading' => true])
            ->assertSessionHas('status', 'meter-reading-last-corrected-approved');

        $this->assertSame([MeterReadingStatus::Approved, MeterReadingStatus::Pending], [$lastWeek->fresh()->status, $thisWeek->fresh()->status]);
        $this->assertSame(20.0, $this->subscription->balance());
    }

    public function test_this_weeks_reading_must_be_typed_again_and_is_recalculated_from_what_was_typed(): void
    {
        $lastWeek = $this->reading('2026-09-11', 1200, 1250);
        $thisWeek = $this->reading('2026-09-18', 1250, 1300);
        $lastWeek->approve($this->accountant);
        $thisWeek->approve($this->accountant);

        $this->actingAs($this->dataEntry)
            ->put(route('meter-readings.update', $lastWeek), ['current_reading' => 1240, 'as_last_reading' => true])
            ->assertSessionHasErrors(['next_current_reading' => 'أدخل قراءة هذا الأسبوع من جديد بعد تعديل القراءة السابقة.']);
        $this->put(route('meter-readings.update', $lastWeek), ['current_reading' => 1240, 'next_current_reading' => 1235, 'as_last_reading' => true])
            ->assertSessionHasErrors(['next_current_reading' => 'القراءة الحالية لا يمكن أن تكون أقل من القراءة السابقة (1240).']);

        $this->assertSame(1250.0, $lastWeek->fresh()->current_reading);
        $this->assertSame([1250.0, 1300.0], [$thisWeek->fresh()->previous_reading, $thisWeek->fresh()->current_reading]);

        $this->put(route('meter-readings.update', $lastWeek), ['current_reading' => 1240, 'next_current_reading' => 1310, 'as_last_reading' => true])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'meter-reading-last-corrected-approved');

        $thisWeek->refresh();
        $this->assertSame([1240.0, 1310.0, 70.0, '35.00', MeterReadingStatus::Approved], [$thisWeek->previous_reading, $thisWeek->current_reading, $thisWeek->consumption, $thisWeek->amount_due, $thisWeek->status]);
        $this->assertSame(20.0 + 35.0, $this->subscription->balance());
    }

    public function test_the_preview_shows_what_the_edit_does_to_both_weeks_without_saving_it(): void
    {
        $lastWeek = $this->reading('2026-09-11', 1200, 1250);
        $thisWeek = $this->reading('2026-09-18', 1250, 1300);
        $lastWeek->approve($this->accountant);

        $this->actingAs($this->dataEntry)
            ->postJson(route('meter-readings.last-reading-preview', $lastWeek), ['current_reading' => 1240])
            ->assertOk()
            ->assertJsonPath('weeks.0.id', $lastWeek->id)
            ->assertJsonPath('weeks.0.isApproved', true)
            ->assertJsonPath('weeks.0.before.amountDue', '25.00')
            ->assertJsonPath('weeks.0.after.amountDue', '20.00')
            ->assertJsonPath('weeks.0.after.consumption', 40)
            ->assertJsonPath('weeks.1.id', $thisWeek->id)
            ->assertJsonPath('weeks.1.isApproved', false)
            ->assertJsonPath('weeks.1.before.currentReading', 1300)
            ->assertJsonPath('weeks.1.after', null);

        // This week is worked out from its reading typed again, which may pass the number it had.
        $this->postJson(route('meter-readings.last-reading-preview', $lastWeek), ['current_reading' => 1240, 'next_current_reading' => 1310])
            ->assertOk()
            ->assertJsonPath('weeks.1.after.previousReading', 1240)
            ->assertJsonPath('weeks.1.after.currentReading', 1310)
            ->assertJsonPath('weeks.1.after.amountDue', '35.00')
            ->assertJsonPath('weeks.1.unusual', false);
        $this->postJson(route('meter-readings.last-reading-preview', $lastWeek), ['current_reading' => 1340])
            ->assertOk();
        $this->postJson(route('meter-readings.last-reading-preview', $lastWeek), ['current_reading' => 1240, 'next_current_reading' => 1230])
            ->assertJsonValidationErrors(['next_current_reading' => 'القراءة الحالية لا يمكن أن تكون أقل من القراءة السابقة (1240).']);
        $this->actingAs($this->accountant)
            ->postJson(route('meter-readings.last-reading-preview', $lastWeek), ['current_reading' => 1240])
            ->assertForbidden();

        $this->assertSame(1250.0, $lastWeek->fresh()->current_reading);
        $this->assertSame(1250.0, $thisWeek->fresh()->previous_reading);
    }

    public function test_the_last_reading_cannot_pass_this_weeks_reading_or_go_below_its_own_start(): void
    {
        $lastWeek = $this->reading('2026-09-11', 1200, 1250);
        $thisWeek = $this->reading('2026-09-18', 1250, 1300);

        $this->actingAs($this->dataEntry)
            ->put(route('meter-readings.update', $lastWeek), ['current_reading' => 1301])
            ->assertSessionHasErrors(['current_reading' => 'القراءة السابقة لا يمكن أن تكون أكبر من قراءة الأسبوع الذي يليها (1300).']);

        $this->put(route('meter-readings.update', $lastWeek), ['current_reading' => 1199])
            ->assertSessionHasErrors(['current_reading' => 'القراءة الحالية لا يمكن أن تكون أقل من القراءة السابقة (1200).']);

        $this->assertSame(1250.0, $lastWeek->fresh()->current_reading);
        $this->assertSame(1250.0, $thisWeek->fresh()->previous_reading);
    }

    public function test_only_the_week_before_the_latest_can_be_edited_as_the_last_reading(): void
    {
        $twoWeeksBack = $this->reading('2026-09-04', 1150, 1200);
        $this->reading('2026-09-11', 1200, 1250);

        $this->actingAs($this->dataEntry)
            ->put(route('meter-readings.update', $twoWeeksBack), ['current_reading' => 1190])
            ->assertForbidden();

        $this->assertSame(1200.0, $twoWeeksBack->fresh()->current_reading);
    }

    public function test_a_branch_admin_takes_editing_the_last_reading_away_from_their_data_entry(): void
    {
        $lastWeek = $this->reading('2026-09-11', 1200, 1250);
        $branchAdmin = User::factory()->branchAdmin()->grantingWhatTheyHold()->create(['branch_id' => $this->branch->id]);
        $keptPermissions = $this->dataEntry->permissions()
            ->where('key', '!=', PermissionKey::CorrectLastMeterReadings->value)
            ->pluck('permissions.id')->all();

        $this->actingAs($branchAdmin)
            ->put(route('settings.permissions.update'), ['permissions' => [$this->dataEntry->id => $keptPermissions]])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->dataEntry->fresh())
            ->put(route('meter-readings.update', $lastWeek), ['current_reading' => 1240])
            ->assertForbidden();

        $this->assertSame(1250.0, $lastWeek->fresh()->current_reading);
    }

    public function test_the_sheet_offers_the_last_reading_for_editing_only_with_the_permission(): void
    {
        $lastWeek = $this->reading('2026-09-11', 1200, 1250);
        $this->reading('2026-09-18', 1250, 1300);

        $this->actingAs($this->dataEntry)
            ->get(route('meter-readings.index'))
            ->assertInertia(fn ($page) => $page
                ->where('week', '2026-09-18')
                ->where('rows.data.0.previousReading', 1250)
                ->where('rows.data.0.lastReading.id', $lastWeek->id)
                ->where('rows.data.0.lastReading.weekStart', '2026-09-11')
                ->where('rows.data.0.lastReading.canEdit', true));

        $this->dataEntry->permissions()->detach(Permission::idsFor([PermissionKey::CorrectLastMeterReadings]));

        $this->actingAs($this->dataEntry->fresh())
            ->get(route('meter-readings.index'))
            ->assertInertia(fn ($page) => $page->where('rows.data.0.lastReading.canEdit', false));
    }

    public function test_the_subscriptions_reading_history_offers_its_last_reading_for_editing(): void
    {
        $lastWeek = $this->reading('2026-09-11', 1200, 1250);
        $this->reading('2026-09-18', 1250, 1300);

        $this->actingAs($this->dataEntry)
            ->get(route('subscriptions.index'))
            ->assertInertia(fn ($page) => $page->where('subscriptions.data.0.editableLastReadingId', $lastWeek->id));

        $this->actingAs($this->accountant)
            ->get(route('subscriptions.index'))
            ->assertInertia(fn ($page) => $page->where('subscriptions.data.0.editableLastReadingId', null));
    }

    public function test_the_reading_history_offers_only_last_weeks_reading_for_editing(): void
    {
        // Ahmad's last reading is last week's; Badr's latest is two weeks back, which stays locked.
        $this->reading('2026-09-04', 1150, 1200);
        $this->reading('2026-09-11', 1200, 1250);
        $this->reading('2026-09-18', 1250, 1300);
        $twoWeeksBackOnly = Subscription::factory()->create(['branch_id' => $this->branch->id, 'full_name' => 'Badr']);
        MeterReading::factory()->create(['subscription_id' => $twoWeeksBackOnly->id, 'week_start' => '2026-09-04', 'week_end' => '2026-09-10']);

        $this->actingAs($this->dataEntry)
            ->get(route('subscriptions.index', ['sort' => 'full_name']))
            ->assertInertia(fn ($page) => $page
                ->where('subscriptions.data.0.full_name', 'Ahmad')
                ->where('subscriptions.data.0.editableLastReadingId', MeterReading::where('subscription_id', $this->subscription->id)->whereDate('week_start', '2026-09-11')->value('id'))
                ->where('subscriptions.data.1.full_name', 'Badr')
                ->where('subscriptions.data.1.editableLastReadingId', null));
    }

    public function test_new_branch_admins_and_data_entry_may_edit_the_last_reading_but_accountants_may_not(): void
    {
        $this->assertTrue(User::factory()->branchAdmin()->create()->hasPermission(PermissionKey::CorrectLastMeterReadings));
        $this->assertTrue($this->dataEntry->hasPermission(PermissionKey::CorrectLastMeterReadings));
        $this->assertFalse($this->accountant->hasPermission(PermissionKey::CorrectLastMeterReadings));
    }

    /**
     * A reading at 0.50 a kilo with no minimum, so each week's bill is half its consumption.
     */
    private function reading(string $weekStart, int $previous, int $current): MeterReading
    {
        return MeterReading::factory()->create([
            'subscription_id' => $this->subscription->id,
            'week_start' => $weekStart,
            'week_end' => now()->parse($weekStart)->addDays(6),
            'previous_reading' => $previous,
            'current_reading' => $current,
            'consumption' => $current - $previous,
            'unit_price' => '0.50',
            'minimum_payment' => '0.00',
            ...MeterReading::chargesFor($current - $previous, '0.50', '0.00'),
        ]);
    }
}
