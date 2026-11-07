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

class MeterReadingApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-24 10:00:00');

        $this->branch = Branch::factory()->create();
        $this->accountant = User::factory()->accountant()->create(['branch_id' => $this->branch->id]);
    }

    public function test_the_accountant_approves_the_ticked_readings_and_charges_their_subscriptions(): void
    {
        $approved = $this->pendingReading('2026-09-18', '42.50');
        $untouched = $this->pendingReading('2026-09-18', '10.00');

        $this->actingAs($this->accountant)
            ->from(route('meter-readings.index'))
            ->post(route('meter-readings.approve'), ['reading_ids' => [$approved->id]])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'meter-readings-approved')
            ->assertRedirect(route('meter-readings.index'));

        $approved->refresh();
        $this->assertSame(MeterReadingStatus::Approved, $approved->status);
        $this->assertTrue($approved->approvedBy->is($this->accountant));
        $this->assertTrue($approved->approved_at->equalTo(now()));
        $this->assertSame(MeterReadingStatus::Pending, $untouched->fresh()->status);

        $transaction = SubscriptionTransaction::sole();
        $this->assertSame($approved->subscription_id, $transaction->subscription_id);
        $this->assertSame(SubscriptionTransaction::TYPE_METER_READING, $transaction->type);
        $this->assertSame('42.50', $transaction->amount);
        $this->assertTrue($transaction->recordedBy->is($this->accountant));

        $this->actingAs($this->accountant)
            ->get(route('subscriptions.index', ['search' => $approved->subscription->full_name]))
            ->assertInertia(fn ($page) => $page->where('subscriptions.data.0.outstandingBalance', fn ($balance): bool => (float) $balance === 42.5));
    }

    public function test_subscriber_history_exposes_approval_only_for_eligible_readings_and_authorized_users(): void
    {
        $pending = $this->pendingReading('2026-09-18', '5.00', 'History Subscriber');
        $pending->update(['usual_consumption' => 2]);
        $approved = MeterReading::factory()->for($pending->subscription)->create(['week_start' => '2026-09-11', 'status' => MeterReadingStatus::Approved]);
        $reentry = MeterReading::factory()->for($pending->subscription)->create(['week_start' => '2026-09-04', 'requires_reentry' => true]);

        $this->actingAs($this->accountant)->get(route('subscriptions.index', ['search' => 'History Subscriber']))
            ->assertInertia(fn ($page) => $page
                ->where('subscriptions.data.0.meterReadings.0.id', $pending->id)
                ->where('subscriptions.data.0.meterReadings.0.canApprove', true)
                ->where('subscriptions.data.0.meterReadings.0.unusual.usual', 2)
                ->where('subscriptions.data.0.meterReadings.1.id', $approved->id)
                ->where('subscriptions.data.0.meterReadings.1.canApprove', false)
                ->where('subscriptions.data.0.meterReadings.2.id', $reentry->id)
                ->where('subscriptions.data.0.meterReadings.2.canApprove', false));

        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]);
        $this->actingAs($dataEntry)->get(route('subscriptions.index', ['search' => 'History Subscriber']))
            ->assertInertia(fn ($page) => $page->where('subscriptions.data.0.meterReadings.0.canApprove', false));
    }

    public function test_approve_all_takes_the_weeks_pending_readings_matching_the_sheets_search_in_the_accountants_branch(): void
    {
        $matching = [$this->pendingReading('2026-09-18', '5.00', 'Ahmad One'), $this->pendingReading('2026-09-18', '6.00', 'Ahmad Two')];
        $otherName = $this->pendingReading('2026-09-18', '7.00', 'Sara');
        $earlierWeek = $this->pendingReading('2026-09-11', '8.00', 'Ahmad Three');
        $otherBranch = MeterReading::factory()->create([
            'subscription_id' => Subscription::factory()->create(['full_name' => 'Ahmad Elsewhere']),
            'week_start' => '2026-09-18',
        ]);

        $this->actingAs($this->accountant)
            ->post(route('meter-readings.approve'), ['all' => true, 'week' => '2026-09-18', 'search' => 'Ahmad'])
            ->assertSessionHasNoErrors();

        foreach ($matching as $reading) {
            $this->assertSame(MeterReadingStatus::Approved, $reading->fresh()->status);
        }
        foreach ([$otherName, $earlierWeek, $otherBranch] as $reading) {
            $this->assertSame(MeterReadingStatus::Pending, $reading->fresh()->status);
        }
        $this->assertDatabaseCount('subscription_transactions', 2);
    }

    public function test_a_reading_is_charged_only_once_and_another_branchs_reading_is_skipped(): void
    {
        $reading = $this->pendingReading('2026-09-18', '42.50');
        $otherBranch = MeterReading::factory()->create();
        $this->actingAs($this->accountant);

        $this->post(route('meter-readings.approve'), ['reading_ids' => [$reading->id]])->assertSessionHasNoErrors();
        $this->post(route('meter-readings.approve'), ['reading_ids' => [$reading->id, $otherBranch->id]])
            ->assertSessionHasErrors(['reading_ids' => 'لا توجد قراءات بانتظار الاعتماد ضمن اختيارك.']);

        $this->assertDatabaseCount('subscription_transactions', 1);
        $this->assertSame(MeterReadingStatus::Pending, $otherBranch->fresh()->status);
    }

    public function test_ticked_readings_are_required_unless_approving_all_of_a_week(): void
    {
        $this->actingAs($this->accountant)
            ->post(route('meter-readings.approve'), [])
            ->assertSessionHasErrors(['reading_ids' => 'اختر قراءة واحدة على الأقل لاعتمادها.']);

        $this->actingAs($this->accountant)
            ->post(route('meter-readings.approve'), ['all' => true])
            ->assertSessionHasErrors('week');
    }

    public function test_the_readings_sheet_offers_approval_of_the_weeks_pending_readings_to_the_accountant(): void
    {
        $pending = $this->pendingReading('2026-09-18', '42.50', 'Ahmad Pending');
        $this->pendingReading('2026-09-18', '7.50', 'Basem Pending');
        $approved = $this->pendingReading('2026-09-18', '1.00', 'Carla Approved');
        $approved->approve($this->accountant);
        $this->pendingReading('2026-09-11', '9.00', 'Dana Earlier');

        $this->actingAs($this->accountant)
            ->get(route('meter-readings.index', ['week' => '2026-09-18']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canApprove', true)
                ->where('entryWindow.appliesToActor', false)
                ->where('pendingApproval.count', 2)
                ->where('pendingApproval.amountDue', '50.00')
                ->where('rows.data', fn ($rows): bool => collect($rows)->mapWithKeys(fn ($row) => [$row['fullName'] => $row['canApprove']])->all() === [
                    'Ahmad Pending' => true,
                    'Basem Pending' => true,
                    'Carla Approved' => false,
                    'Dana Earlier' => false,
                ]));
    }

    public function test_the_readings_sheet_shows_no_approval_to_data_entry(): void
    {
        $this->pendingReading('2026-09-18', '42.50');
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($dataEntry)
            ->get(route('meter-readings.index'))
            ->assertInertia(fn ($page) => $page
                ->where('canApprove', false)
                ->where('pendingApproval', null)
                ->where('rows.data.0.canApprove', false));
    }

    public function test_branch_admins_approve_by_default_but_data_entry_cannot_approve(): void
    {
        $reading = $this->pendingReading('2026-09-18', '42.50');
        $branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $this->branch->id]);
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($dataEntry)->post(route('meter-readings.approve'), ['reading_ids' => [$reading->id]])->assertForbidden();
        $this->assertSame(MeterReadingStatus::Pending, $reading->fresh()->status);

        $this->actingAs($branchAdmin)
            ->post(route('meter-readings.approve'), ['reading_ids' => [$reading->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame(MeterReadingStatus::Approved, $reading->fresh()->status);
    }

    public function test_the_super_admin_can_grant_approving_to_any_employee_from_the_permissions_page(): void
    {
        $collector = User::factory()->collector()->create(['branch_id' => $this->branch->id]);
        $approve = Permission::idsFor([PermissionKey::ApproveMeterReadings])[0];

        $this->actingAs(User::factory()->superAdmin()->create())
            ->put(route('settings.permissions.update'), ['permissions' => [$collector->id => [$approve]]])
            ->assertSessionHasNoErrors();

        $this->actingAs($collector->fresh())
            ->get(route('meter-readings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canApprove', true));
    }

    public function test_correcting_an_approved_reading_sends_it_back_for_approval_and_cancels_its_charge_on_the_statement(): void
    {
        $reading = $this->pendingReading('2026-09-18', '42.50');
        $reading->update(['previous_reading' => 1000, 'current_reading' => 1100, 'consumption' => 100, 'unit_price' => '0.50', 'minimum_payment' => '10.00']);
        $reading->approve($this->accountant);
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id, 'name' => 'Elenora']);

        $this->actingAs($dataEntry)
            ->from(route('meter-readings.index'))
            ->put(route('meter-readings.update', $reading), ['current_reading' => 1080])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'meter-reading-reopened');

        $reading->refresh();
        $this->assertSame(MeterReadingStatus::Pending, $reading->status);
        $this->assertNull($reading->approved_by);
        $this->assertNull($reading->approved_at);
        $this->assertSame('40.00', $reading->amount_due);

        // The charge stays on the statement, cancelled by a reversal, so it no longer counts.
        $charge = SubscriptionTransaction::where('type', SubscriptionTransaction::TYPE_METER_READING)->sole();
        $this->assertSame(['reading_corrected', $dataEntry->id], [$charge->cancellation_reason->value, $charge->cancelled_by]);
        $this->assertSame('-42.50', SubscriptionTransaction::where('reverses_id', $charge->id)->sole()->amount);
        $this->assertSame(0.0, $reading->subscription->balance());

        // Approving it again charges the corrected amount, as the correction of the cancelled line.
        $reading->approve($this->accountant);
        $corrected = SubscriptionTransaction::where('type', SubscriptionTransaction::TYPE_METER_READING)->whereNull('cancelled_at')->sole();
        $this->assertSame(['40.00', $charge->id], [$corrected->amount, $corrected->corrects_id]);
        $this->assertSame(40.0, $reading->subscription->balance());
    }

    public function test_the_people_who_approve_are_told_when_an_approved_reading_is_corrected(): void
    {
        $reading = $this->pendingReading('2026-09-18', '42.50', 'Ahmad');
        $reading->approve($this->accountant);
        $superAdmin = User::factory()->superAdmin()->create();
        $otherBranchAccountant = User::factory()->accountant()->create();
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id, 'name' => 'Elenora']);

        $this->actingAs($dataEntry)
            ->put(route('meter-readings.update', $reading), ['current_reading' => $reading->current_reading + 5])
            ->assertSessionHasNoErrors();

        $expected = ['action' => 'meter-reading-needs-reapproval', 'subject' => 'Ahmad — عدّلها Elenora'];
        $this->assertSame($expected, $this->accountant->notifications()->sole()->data);
        $this->assertSame($expected, $superAdmin->notifications()->sole()->data);
        $this->assertSame(0, $otherBranchAccountant->notifications()->count());
        $this->assertSame(0, $dataEntry->notifications()->where('data->action', 'meter-reading-needs-reapproval')->count());
    }

    public function test_correcting_a_pending_reading_does_not_notify_the_people_who_approve(): void
    {
        $reading = $this->pendingReading('2026-09-18', '42.50');
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($dataEntry)
            ->put(route('meter-readings.update', $reading), ['current_reading' => $reading->current_reading + 5])
            ->assertSessionHas('status', 'meter-reading-updated');

        $this->assertSame(0, $this->accountant->notifications()->count());
    }

    public function test_the_readings_sheet_can_be_filtered_by_approval(): void
    {
        $this->pendingReading('2026-09-18', '5.00', 'Ahmad Pending');
        $this->pendingReading('2026-09-18', '6.00', 'Basem Approved')->approve($this->accountant);
        $this->actingAs($this->accountant);

        foreach (['pending' => 'Ahmad Pending', 'approved' => 'Basem Approved'] as $approval => $name) {
            $this->get(route('meter-readings.index', ['week' => '2026-09-18', 'filter' => ['approval' => $approval]]))
                ->assertInertia(fn ($page) => $page->has('rows.data', 1)->where('rows.data.0.fullName', $name));
        }
    }

    public function test_status_counts_keep_the_search_and_location_filters_across_status_tabs(): void
    {
        $pending = $this->pendingReading('2026-09-18', '5.00', 'Ahmad Pending');
        $approved = $this->pendingReading('2026-09-18', '6.00', 'Ahmad Approved');
        $approved->subscription->update(['meter_box_id' => $pending->subscription->meter_box_id]);
        $approved->approve($this->accountant);
        Subscription::factory()->create([
            'branch_id' => $this->branch->id,
            'meter_box_id' => $pending->subscription->meter_box_id,
            'full_name' => 'Ahmad Missing',
        ]);
        $this->pendingReading('2026-09-18', '7.00', 'Sara');
        $this->pendingReading('2026-09-18', '8.00', 'Ahmad Another Box');
        MeterReading::factory()->create([
            'subscription_id' => Subscription::factory()->create(['full_name' => 'Ahmad Another Branch']),
            'week_start' => '2026-09-18',
        ]);
        $this->actingAs($this->accountant);

        foreach (['pending' => 'Ahmad Pending', 'approved' => 'Ahmad Approved', 'missing' => 'Ahmad Missing'] as $status => $name) {
            $this->get(route('meter-readings.index', [
                'week' => '2026-09-18',
                'search' => 'Ahmad',
                'per_page' => 15,
                'filter' => [
                    'meter_box_id' => $pending->subscription->meter_box_id,
                    'entry' => $status === 'missing' ? 'missing' : '',
                    'approval' => $status === 'missing' ? '' : $status,
                ],
            ]))->assertInertia(fn ($page) => $page
                ->where('statusSummary', ['total' => 3, 'pending' => 1, 'approved' => 1, 'missing' => 1])
                ->has('rows.data', 1)
                ->where('rows.data.0.fullName', $name)
                ->where('pendingApproval.count', $status === 'pending' ? 1 : 0));
        }
    }

    public function test_the_sheet_identifies_the_recorder_source_and_approver_in_business_time(): void
    {
        config(['app.business_timezone' => 'Asia/Gaza']);
        $recorder = User::factory()->dataEntry()->create(['name' => 'Reading Recorder']);
        $reading = $this->pendingReading('2026-09-18', '5.00');
        $reading->update([
            'recorded_by' => $recorder->id,
            'mobile_operation_id' => fake()->uuid(),
            'created_at' => '2026-09-24 10:00:00',
        ]);
        $reading->approve($this->accountant);
        $this->accountant->permissions()->attach(Permission::idsFor([PermissionKey::ViewTransactionAudit]));

        $this->actingAs($this->accountant)
            ->get(route('meter-readings.index', ['week' => '2026-09-18']))
            ->assertInertia(fn ($page) => $page
                ->where('rows.data.0.reading.recordedByName', 'Reading Recorder')
                ->where('rows.data.0.reading.recordedSource', 'app')
                ->where('rows.data.0.reading.recordedAt', '24/09 13:00')
                ->where('rows.data.0.reading.approvedByName', $this->accountant->name)
                ->where('rows.data.0.reading.approvedAt', '24/09 13:00'));
    }

    private function pendingReading(string $weekStart, string $amountDue, ?string $subscriptionName = null): MeterReading
    {
        return MeterReading::factory()->create([
            'subscription_id' => Subscription::factory()->create(array_filter(['branch_id' => $this->branch->id, 'full_name' => $subscriptionName])),
            'week_start' => $weekStart,
            'week_end' => now()->parse($weekStart)->addDays(6),
            'amount_due' => $amountDue,
        ]);
    }
}
