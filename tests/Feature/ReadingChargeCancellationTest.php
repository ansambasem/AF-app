<?php

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\CorrectionReason;
use App\Enums\DiscountMethod;
use App\Enums\MeterReadingStatus;
use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\MeterReading;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadingChargeCancellationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-24 10:00:00');
        $this->branch = Branch::factory()->create();
        $this->admin = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions, PermissionKey::ApproveMeterReadings, PermissionKey::CorrectMeterReadings])->create(['branch_id' => $this->branch->id]);
        $this->subscription = Subscription::factory()->create(['branch_id' => $this->branch->id, 'initial_reading' => 1000]);
    }

    public function test_cancelling_a_readings_charge_from_the_statement_sends_the_reading_back_for_approval(): void
    {
        [$reading, $charge] = $this->approvedReading('210.00');
        SubscriptionTransaction::recordCharge($this->subscription, $this->admin, ChargeType::Penalty, '10', 'غرامة');

        $this->actingAs($this->admin)->post(route('subscriptions.transactions.actions.store', [$this->subscription, $charge]), [
            'action' => 'cancel', 'correction_reason' => 'wrong_reading', 'correction_notes' => 'قراءة خاطئة', 'reopen_reading' => true,
        ])->assertSessionHasNoErrors();

        $reading->refresh();
        $this->assertSame(MeterReadingStatus::Pending, $reading->status);
        $this->assertNull($reading->approved_by);
        $this->assertNull($reading->approved_at);
        $this->assertTrue($charge->refresh()->isCancelled());
        // Only the penalty is owed: the cancelled reading is no longer billed.
        $this->assertSame(10.0, $this->subscription->balance());
    }

    public function test_the_reading_is_then_approved_again_and_billed_afresh_under_the_cancelled_line(): void
    {
        [$reading, $charge] = $this->approvedReading('210.00');
        SubscriptionTransaction::recordCharge($this->subscription, $this->admin, ChargeType::Penalty, '10', 'غرامة');
        $charge->cancel($this->admin, CorrectionReason::WrongReading, 'خطأ', reopenReading: true);

        $this->actingAs($this->admin)->post(route('meter-readings.approve'), ['reading_ids' => [$reading->id]])->assertSessionHasNoErrors();

        $this->assertSame(MeterReadingStatus::Approved, $reading->fresh()->status);
        $rebilled = SubscriptionTransaction::where('meter_reading_id', $reading->id)->where('type', 'meter_reading')->whereNull('cancelled_at')->sole();
        $this->assertSame('210.00', $rebilled->amount);
        $this->assertSame($charge->id, $rebilled->corrects_id);
        $this->assertSame($reading->chargeSourceKey(), $rebilled->source_key);
        $this->assertSame(220.0, $this->subscription->balance());
    }

    public function test_the_weeks_reading_can_be_corrected_after_its_charge_was_cancelled(): void
    {
        [$reading, $charge] = $this->approvedReading('210.00');
        SubscriptionTransaction::recordCharge($this->subscription, $this->admin, ChargeType::Penalty, '10', 'غرامة');
        $charge->cancel($this->admin, CorrectionReason::WrongReading, 'خطأ', reopenReading: true);

        $this->actingAs($this->admin)->get(route('meter-readings.index', ['week' => '2026-09-18']))->assertInertia(fn ($page) => $page
            ->where('rows.data', fn ($rows): bool => collect($rows)->contains(fn (array $row): bool => ($row['reading']['id'] ?? null) === $reading->id
                && $row['reading']['status'] === 'pending' && $row['canEdit'] === true)));

        $this->put(route('meter-readings.update', $reading), ['current_reading' => 1040])->assertSessionHasNoErrors();
        $this->post(route('meter-readings.approve'), ['reading_ids' => [$reading->id]])->assertSessionHasNoErrors();

        $this->assertSame(1040.0, $reading->fresh()->current_reading);
        $this->assertSame(MeterReadingStatus::Approved, $reading->fresh()->status);
    }

    public function test_deleting_a_readings_charge_by_its_legacy_route_does_the_same(): void
    {
        [$reading, $charge] = $this->approvedReading('210.00');
        SubscriptionTransaction::recordCharge($this->subscription, $this->admin, ChargeType::Penalty, '10', 'غرامة');

        $this->actingAs($this->admin)->delete(route('subscriptions.transactions.destroy', [$this->subscription, $charge]), ['correction_reason' => 'wrong_reading', 'correction_notes' => 'خطأ', 'reopen_reading' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame(MeterReadingStatus::Pending, $reading->fresh()->status);
        $this->assertNotSame($reading->chargeSourceKey(), $charge->fresh()->source_key);
    }

    public function test_cancelling_a_reading_with_a_standing_discount_frees_both_lines_for_billing_again(): void
    {
        [$reading, $charge, $discount] = $this->approvedReading('83.40', discount: '30.00');
        SubscriptionTransaction::recordCharge($this->subscription, $this->admin, ChargeType::Penalty, '10', 'غرامة');

        $this->actingAs($this->admin)->post(route('subscriptions.transactions.actions.store', [$this->subscription, $charge]), [
            'action' => 'cancel', 'correction_reason' => 'wrong_reading', 'correction_notes' => 'خطأ', 'reopen_reading' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame(10.0, $this->subscription->balance());
        $this->assertSame(MeterReadingStatus::Pending, $reading->fresh()->status);

        $this->post(route('meter-readings.approve'), ['reading_ids' => [$reading->id]])->assertSessionHasNoErrors();

        $this->assertSame(['83.40', '-30.00'], SubscriptionTransaction::where('meter_reading_id', $reading->id)->whereNull('cancelled_at')->whereNull('reverses_id')->orderBy('id')->pluck('amount')->all());
        $this->assertSame(63.4, round($this->subscription->balance(), 2));
        $this->assertNotNull($discount->fresh()->cancelled_at);
    }

    public function test_deleting_the_cancellation_puts_the_charge_and_the_approved_reading_back_as_they_were(): void
    {
        [$reading, $charge] = $this->approvedReading('210.00');
        $charge->cancel($this->admin, CorrectionReason::WrongReading, 'خطأ', reopenReading: true);
        $reversal = SubscriptionTransaction::where('reverses_id', $charge->id)->sole();

        $this->actingAs($this->admin)->post(route('subscriptions.transactions.actions.store', [$this->subscription, $reversal]), ['action' => 'delete_reversal', 'correction_notes' => 'الإلغاء كان خطأ'])
            ->assertSessionHasNoErrors();

        $charge->refresh();
        $this->assertFalse($charge->isCancelled());
        $this->assertSame($reading->chargeSourceKey(), $charge->source_key);
        $this->assertSame(MeterReadingStatus::Approved, $reading->fresh()->status);
        $this->assertSame($this->admin->id, $reading->fresh()->approved_by);
        $this->assertSame(210.0, $this->subscription->balance());
    }

    public function test_without_asking_for_a_review_cancelling_a_readings_charge_waives_it_and_the_reading_stays_approved(): void
    {
        [$reading, $charge] = $this->approvedReading('210.00');
        SubscriptionTransaction::recordCharge($this->subscription, $this->admin, ChargeType::Penalty, '10', 'غرامة');

        $this->actingAs($this->admin)->post(route('subscriptions.transactions.actions.store', [$this->subscription, $charge]), [
            'action' => 'cancel', 'correction_reason' => 'duplicate', 'correction_notes' => 'إعفاء',
        ])->assertSessionHasNoErrors();

        $this->assertSame(MeterReadingStatus::Approved, $reading->fresh()->status);
        $this->assertSame($reading->chargeSourceKey(), $charge->fresh()->source_key);
        $this->assertSame(10.0, $this->subscription->balance());
    }

    public function test_the_sheet_says_when_an_approved_readings_bill_was_cancelled_and_not_otherwise(): void
    {
        [$waived, $charge] = $this->approvedReading('210.00');
        $charge->cancel($this->admin, CorrectionReason::Duplicate, 'إعفاء');
        $billed = $this->otherSubscriptionsApprovedReading();

        $this->actingAs($this->admin)->get(route('meter-readings.index', ['week' => '2026-09-18']))->assertInertia(fn ($page) => $page
            ->where('rows.data', function ($rows) use ($waived, $billed): bool {
                $reading = fn (MeterReading $reading): array => collect($rows)->first(fn (array $row): bool => ($row['reading']['id'] ?? null) === $reading->id)['reading'];

                return $reading($waived)['status'] === 'approved' && $reading($waived)['billed'] === false
                    && $reading($billed)['status'] === 'approved' && $reading($billed)['billed'] === true;
            }));
    }

    public function test_the_sheet_loads_the_bill_state_of_all_its_rows_in_one_query(): void
    {
        foreach (range(1, 6) as $unused) {
            $this->otherSubscriptionsApprovedReading();
        }

        DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('meter-readings.index', ['week' => '2026-09-18']))->assertOk();
        $charges = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "subscription_transactions"') && str_contains($query['query'], '"meter_reading_id" in'));

        $this->assertCount(1, $charges);
    }

    public function test_correcting_the_reading_itself_still_works_as_before(): void
    {
        [$reading] = $this->approvedReading('30.00');

        $this->actingAs($this->admin)->put(route('meter-readings.update', $reading), ['current_reading' => 1020, 'approve' => true])->assertSessionHasNoErrors();

        $this->assertSame(MeterReadingStatus::Approved, $reading->fresh()->status);
        // 20 kWh at 3 shekels.
        $this->assertSame(60.0, $this->subscription->balance());
    }

    /** An approved, billed reading of another subscription of the branch, for the same week. */
    private function otherSubscriptionsApprovedReading(): MeterReading
    {
        $reading = MeterReading::factory()->approved()->create([
            'subscription_id' => Subscription::factory()->create(['branch_id' => $this->branch->id, 'initial_reading' => 0])->id,
            'week_start' => '2026-09-18', 'week_end' => '2026-09-24',
            'previous_reading' => 0, 'current_reading' => 10, 'consumption' => 10, 'unit_price' => '3.00',
            'reading_fee' => '30.00', 'minimum_payment' => '0.00', 'discount_amount' => '0.00', 'amount_due' => '30.00',
            'status' => MeterReadingStatus::Pending,
        ]);
        $reading->approve($this->admin);

        return $reading;
    }

    /**
     * An approved week's reading with its charge on the account (and a standing discount line when asked).
     *
     * @return array{0: MeterReading, 1: SubscriptionTransaction, 2: ?SubscriptionTransaction}
     */
    private function approvedReading(string $amountBeforeDiscount, ?string $discount = null): array
    {
        $reading = MeterReading::factory()->for($this->subscription)->create([
            'branch_id' => $this->branch->id,
            'recorded_by' => $this->admin->id,
            'week_start' => '2026-09-18',
            'week_end' => '2026-09-24',
            'previous_reading' => 1000,
            'current_reading' => 1010,
            'consumption' => 10,
            'unit_price' => '3.00',
            'reading_fee' => $amountBeforeDiscount,
            'minimum_payment' => '0.00',
            'discount_method' => $discount ? DiscountMethod::Kilowatt : null,
            'discount_value' => $discount ? '10' : null,
            'discount_segment' => $discount ? 'عائلات' : null,
            'discount_amount' => $discount ?? '0.00',
            'amount_due' => number_format((float) $amountBeforeDiscount - (float) $discount, 2, '.', ''),
        ]);
        $reading->approve($this->admin);

        return [
            $reading,
            SubscriptionTransaction::where('meter_reading_id', $reading->id)->where('type', 'meter_reading')->sole(),
            SubscriptionTransaction::where('meter_reading_id', $reading->id)->where('type', 'reading_discount')->first(),
        ];
    }
}
