<?php

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\MeterReadingStatus;
use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\MeterReading;
use App\Models\StandingDiscount;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\TransactionDeletion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SubscriptionReadingLedgerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith([0, 180.0, 1])]
    #[TestWith([2, 120.0, 2])]
    #[TestWith([6, 0.0, 2])]
    public function test_an_erased_reading_bill_can_be_corrected_and_billed_again_without_a_duplicate_week(
        int $freeKilowatts,
        float $correctedBalance,
        int $lineCount,
    ): void {
        [$actor, $subscription, $reading, $charge] = $this->approvedWeek($freeKilowatts);
        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $charge]), [
            'action' => 'delete', 'correction_notes' => 'Remove the mistaken bill',
        ])->assertSessionHasNoErrors();
        $this->assertSame(0.0, $subscription->balance());
        $this->assertSame(MeterReadingStatus::Approved, $reading->fresh()->status);
        $this->assertCount($lineCount, TransactionDeletion::sole()->transactions);

        $this->post(route('meter-readings.store'), [
            'subscription_id' => $subscription->id, 'week_start' => '2026-09-18', 'current_reading' => 1206,
        ])->assertSessionHasErrors(['week_start' => 'تم تسجيل قراءة لهذا المشترك في هذا الأسبوع مسبقًا.']);
        $this->assertDatabaseCount('meter_readings', 1);
        $this->assertDatabaseCount('subscription_transactions', 0);

        $this->put(route('meter-readings.update', $reading), ['current_reading' => 1206, 'approve' => true])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'meter-reading-corrected-approved');
        $this->post(route('meter-readings.approve'), ['reading_ids' => [$reading->id]])
            ->assertSessionHasErrors(['reading_ids' => 'لا توجد قراءات بانتظار الاعتماد ضمن اختيارك.']);

        $this->assertSame($correctedBalance, $subscription->balance());
        $this->assertDatabaseCount('subscription_transactions', $lineCount);
        $this->get(route('subscriptions.statement', $subscription))->assertInertia(fn ($page) => $page
            ->has('entries', $lineCount)
            ->where('summary.balance', number_format($correctedBalance, 2, '.', '')));
    }

    #[TestWith([0])]
    #[TestWith([2])]
    public function test_a_manually_cancelled_reading_stays_waived_after_correction_and_reapproval(int $freeKilowatts): void
    {
        [$actor, $subscription, $reading, $charge] = $this->approvedWeek($freeKilowatts);
        SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '10', 'Later charge');
        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $charge]), [
            'action' => 'cancel', 'correction_notes' => 'Waive the bill',
        ])->assertSessionHasNoErrors();
        $this->assertSame(10.0, $subscription->balance());

        $this->put(route('meter-readings.update', $reading), ['current_reading' => 1206, 'approve' => true])
            ->assertSessionHasNoErrors();
        $this->post(route('meter-readings.approve'), ['reading_ids' => [$reading->id]])
            ->assertSessionHasErrors(['reading_ids' => 'لا توجد قراءات بانتظار الاعتماد ضمن اختيارك.']);

        $this->assertSame(10.0, $subscription->balance());
        $this->assertSame(0, $subscription->transactions()->where('meter_reading_id', $reading->id)
            ->whereIn('type', [SubscriptionTransaction::TYPE_METER_READING, SubscriptionTransaction::TYPE_READING_DISCOUNT])
            ->whereNull('cancelled_at')->count());
        $this->assertSame($reading->chargeSourceKey(), $charge->fresh()->source_key);
    }

    #[TestWith([0])]
    #[TestWith([2])]
    public function test_giving_a_discount_after_erasing_the_bill_does_not_create_credit_without_a_charge(int $freeKilowatts): void
    {
        [$actor, $subscription, $reading, $charge] = $this->approvedWeek($freeKilowatts);
        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $charge]), [
            'action' => 'delete', 'correction_notes' => 'Remove the mistaken bill',
        ])->assertSessionHasNoErrors();

        $this->put(route('subscriptions.standing-discount.update', $subscription), ['method' => 'percentage', 'value' => '10'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0.0, $subscription->balance());
        $this->assertSame(0, $subscription->transactions()->where('meter_reading_id', $reading->id)->count());
        $this->assertDatabaseHas('standing_discounts', ['subscription_id' => $subscription->id, 'value' => '10.00']);
    }

    public function test_giving_a_first_discount_after_cancelling_the_bill_does_not_create_credit_without_a_charge(): void
    {
        [$actor, $subscription, $reading, $charge] = $this->approvedWeek();
        SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '10', 'Later charge');
        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $charge]), [
            'action' => 'cancel', 'correction_notes' => 'Waive the bill',
        ])->assertSessionHasNoErrors();

        $this->put(route('subscriptions.standing-discount.update', $subscription), ['method' => 'percentage', 'value' => '10'])
            ->assertSessionHasNoErrors();

        $this->assertSame(10.0, $subscription->balance());
        $this->assertSame(0, $subscription->transactions()->where('meter_reading_id', $reading->id)
            ->where('type', SubscriptionTransaction::TYPE_READING_DISCOUNT)->whereNull('cancelled_at')->count());
    }

    public function test_a_cancelled_undiscounted_bill_does_not_gain_an_orphan_discount_when_corrected_and_reapproved(): void
    {
        [$actor, $subscription, $reading, $charge] = $this->approvedWeek();
        SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '10', 'Later charge');
        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $charge]), [
            'action' => 'cancel', 'correction_notes' => 'Waive the bill',
        ])->assertSessionHasNoErrors();
        $this->put(route('meter-readings.update', $reading), ['current_reading' => 1206])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'meter-reading-reopened');
        $this->put(route('subscriptions.standing-discount.update', $subscription), ['method' => 'percentage', 'value' => '10'])
            ->assertSessionHasNoErrors();
        $this->assertSame(10.0, $subscription->balance());

        $this->post(route('meter-readings.approve'), ['reading_ids' => [$reading->id]])->assertSessionHasNoErrors();

        $this->assertSame(10.0, $subscription->balance());
        $this->assertSame(0, $subscription->transactions()->where('meter_reading_id', $reading->id)
            ->where('type', SubscriptionTransaction::TYPE_READING_DISCOUNT)->whereNull('cancelled_at')->count());
    }

    public function test_the_next_week_uses_the_retained_meter_value_after_its_bill_was_erased(): void
    {
        [$actor, $subscription, $reading, $charge] = $this->approvedWeek(2);
        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $charge]), [
            'action' => 'delete', 'correction_notes' => 'Remove the mistaken bill',
        ])->assertSessionHasNoErrors();
        $this->travelTo('2026-10-01 10:00:00');

        $this->post(route('meter-readings.store'), [
            'subscription_id' => $subscription->id, 'week_start' => '2026-09-25', 'current_reading' => 1208,
        ])->assertSessionHasNoErrors();
        $nextReading = $subscription->meterReadings()->whereDate('week_start', '2026-09-25')->sole();
        $this->post(route('meter-readings.approve'), ['reading_ids' => [$nextReading->id]])->assertSessionHasNoErrors();

        $this->assertSame([1205.0, 3.0, '30.00'], [$nextReading->previous_reading, $nextReading->consumption, $nextReading->amount_due]);
        $this->assertSame(30.0, $subscription->balance());
        $this->assertSame(0, $subscription->transactions()->where('meter_reading_id', $reading->id)->count());
    }

    public function test_repeated_reading_corrections_preserve_the_payment_and_link_each_replacement(): void
    {
        [$actor, $subscription, $reading, $charge] = $this->approvedWeek(2);
        $payment = SubscriptionTransaction::recordPayment($subscription, $actor, [
            'amount' => '20', 'currency' => 'ILS', 'payment_method' => 'cash',
        ]);
        $this->actingAs($actor)->put(route('meter-readings.update', $reading), ['current_reading' => 1206, 'approve' => true])
            ->assertSessionHasNoErrors();
        $firstReplacement = $subscription->transactions()->where('type', SubscriptionTransaction::TYPE_METER_READING)
            ->whereNull('cancelled_at')->sole();
        $this->assertSame($charge->id, $firstReplacement->corrects_id);
        $this->assertSame(100.0, $subscription->balance());

        $this->put(route('meter-readings.update', $reading), ['current_reading' => 1207, 'approve' => true])
            ->assertSessionHasNoErrors();

        $replacement = $subscription->transactions()->where('type', SubscriptionTransaction::TYPE_METER_READING)
            ->whereNull('cancelled_at')->sole();
        $this->assertSame($firstReplacement->id, $replacement->corrects_id);
        $this->assertSame(['-20.00', SubscriptionTransaction::STATUS_ACTIVE], [$payment->fresh()->amount, $payment->fresh()->status]);
        $this->assertSame(130.0, $subscription->balance());
        $this->assertSame(2, $subscription->transactions()->where('meter_reading_id', $reading->id)
            ->whereIn('type', [SubscriptionTransaction::TYPE_METER_READING, SubscriptionTransaction::TYPE_READING_DISCOUNT])
            ->whereNull('cancelled_at')->count());
        $this->get(route('subscriptions.statement', $subscription))->assertInertia(fn ($page) => $page
            ->where('summary.balance', '130.00')->where('summary.paid', '20.00')->where('summary.discounted', '60.00'));
    }

    public function test_a_bill_that_replaced_a_corrected_bill_can_be_cancelled_but_never_deleted_or_erased(): void
    {
        [$actor, $subscription, $reading, $charge] = $this->approvedWeek();
        $this->actingAs($actor)->put(route('meter-readings.update', $reading), ['current_reading' => 1206, 'approve' => true])->assertSessionHasNoErrors();
        $replacement = $subscription->transactions()->where('corrects_id', $charge->id)->sole();
        $this->get(route('subscriptions.statement', $subscription))->assertInertia(fn ($page) => $page
            ->where('entries.2.id', $replacement->id)
            ->where('entries.2.available_actions', ['cancel'])
            ->where('entries.2.canForceDelete', false));

        $this->post(route('subscriptions.transactions.actions.store', [$subscription, $replacement]), ['action' => 'delete', 'correction_notes' => 'محاولة حذف'])->assertSessionHasErrors('action');
        $this->delete(route('subscriptions.transactions.force-destroy', [$subscription, $replacement]), ['correction_notes' => 'محاولة حذف نهائي'])->assertForbidden();

        $this->assertModelExists($replacement);
        $this->assertSame('180.00', $reading->fresh()->amount_due);
        $this->assertSame(180.0, $subscription->balance());
        $this->assertDatabaseCount('transaction_deletions', 0);
    }

    public function test_a_cancelled_bill_is_not_brought_back_once_its_reading_was_corrected(): void
    {
        [$actor, $subscription, $reading, $charge] = $this->approvedWeek();
        $this->actingAs($actor)->delete(route('subscriptions.transactions.destroy', [$subscription, $charge]), [
            'correction_reason' => 'wrong_reading', 'correction_notes' => 'قراءة خاطئة', 'reopen_reading' => true,
        ])->assertSessionHasNoErrors();
        $this->put(route('meter-readings.update', $reading), ['current_reading' => 1206])->assertSessionHasNoErrors();
        $cancellation = $subscription->transactions()->where('reverses_id', $charge->id)->sole();
        $this->get(route('subscriptions.statement', $subscription))->assertInertia(fn ($page) => $page
            ->where('entries.1.id', $cancellation->id)
            ->where('entries.1.available_actions', []));

        $this->post(route('subscriptions.transactions.actions.store', [$subscription, $cancellation]), ['action' => 'delete_reversal', 'correction_notes' => 'إعادة المبلغ'])->assertSessionHasErrors('action');

        $this->assertModelExists($cancellation);
        $this->assertTrue($charge->fresh()->isCancelled());
        $this->assertSame(MeterReadingStatus::Pending, $reading->fresh()->status);
        $this->assertSame(0.0, $subscription->balance());
    }

    public function test_a_stale_delete_request_is_rejected_after_a_newer_payment_is_added(): void
    {
        [$actor, $subscription, $reading, $charge] = $this->approvedWeek(2);
        $this->actingAs($actor)->get(route('subscriptions.statement', $subscription))
            ->assertInertia(fn ($page) => $page->where('entries.0.available_actions', ['delete']));
        SubscriptionTransaction::recordPayment($subscription, $actor, ['amount' => '20', 'currency' => 'ILS', 'payment_method' => 'cash']);

        $this->post(route('subscriptions.transactions.actions.store', [$subscription, $charge]), [
            'action' => 'delete', 'correction_notes' => 'Stale menu',
        ])->assertSessionHasErrors(['action' => 'هذا الإجراء غير مسموح لهذه الحركة.']);

        $this->assertModelExists($charge);
        $this->assertSame(70.0, $subscription->balance());
        $this->assertDatabaseCount('transaction_deletions', 0);
    }

    public function test_replaying_a_deleted_bill_request_does_not_delete_its_replacement(): void
    {
        [$actor, $subscription, $reading, $charge] = $this->approvedWeek(2);
        $url = route('subscriptions.transactions.actions.store', [$subscription, $charge]);
        $payload = ['action' => 'delete', 'correction_notes' => 'Remove the mistaken bill'];
        $this->actingAs($actor)->post($url, $payload)->assertSessionHasNoErrors();
        $this->put(route('meter-readings.update', $reading), ['current_reading' => 1206, 'approve' => true])->assertSessionHasNoErrors();

        $this->post($url, $payload)->assertNotFound();

        $this->assertSame(120.0, $subscription->balance());
        $this->assertDatabaseCount('subscription_transactions', 2);
        $this->assertDatabaseCount('transaction_deletions', 1);
    }

    /** @return array{User, Subscription, MeterReading, SubscriptionTransaction} */
    private function approvedWeek(int $freeKilowatts = 0): array
    {
        $this->travelTo('2026-09-24 10:00:00');
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id, 'initial_reading' => 1200, 'minimum_charge' => 20]);
        $subscription->tariff->update(['rate' => '30.00']);

        if ($freeKilowatts > 0) {
            StandingDiscount::factory()->for($subscription)->kilowatts($freeKilowatts)->create(['granted_by' => $actor->id]);
        }

        $this->actingAs($actor)->post(route('meter-readings.store'), [
            'subscription_id' => $subscription->id, 'week_start' => '2026-09-18', 'current_reading' => 1205,
        ])->assertSessionHasNoErrors();
        $reading = $subscription->meterReadings()->sole();
        $this->post(route('meter-readings.approve'), ['reading_ids' => [$reading->id]])->assertSessionHasNoErrors();
        $charge = $subscription->transactions()->where('type', SubscriptionTransaction::TYPE_METER_READING)->sole();

        return [$actor, $subscription, $reading, $charge];
    }
}
