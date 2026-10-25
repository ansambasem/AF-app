<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LateSubscriptionPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_correcting_a_late_payment_preserves_its_actual_date_and_reason_without_reusing_the_request_token(): void
    {
        [, $collector, $subscription] = $this->account();
        $head = User::factory()->branchAdmin()->create(['branch_id' => $collector->branch_id]);
        $payload = $this->payload();
        $this->archivedPayment($collector, $subscription, $payload);
        $payment = $subscription->transactions()->where('type', 'payment')->sole();
        $this->travelTo('2026-10-22 08:00:00');

        $this->actingAs($head)->put(route('subscriptions.transactions.update', [$subscription, $payment]), [
            'amount' => '120', 'currency' => 'ILS', 'payment_method' => 'cash',
            'paid_on' => '2026-10-01', 'late_reason' => 'تغيير غير مسموح',
            'correction_reason' => 'wrong_amount', 'correction_notes' => 'المبلغ الصحيح مئة وعشرون',
        ])->assertSessionHasNoErrors();

        $replacement = $payment->fresh()->correction;
        $this->assertSame(230.0, $subscription->balance());
        $this->assertSame('2026-10-10', $replacement->paid_on->toDateString());
        $this->assertSame('نسيان إدخال الوصل', $replacement->late_reason);
        $this->assertTrue($replacement->created_at->equalTo(now()));
        $this->assertNull($replacement->late_payment_token);
        $this->assertSame($payload['late_payment_token'], $payment->late_payment_token);
        $this->get(route('subscriptions.payments.receipt', [$subscription, $replacement]))->assertInertia(fn (AssertableInertia $page) => $page->where('receipt.paidOn', '2026-10-10')->where('receipt.lateReason', 'نسيان إدخال الوصل'));
        $this->get(route('ledger.index', ['period' => 'custom', 'from' => '2026-10-10', 'to' => '2026-10-10', 'date_basis' => 'paid']))->assertInertia(fn (AssertableInertia $page) => $page->where('entries.data', function ($entries) use ($replacement): bool {
            $this->assertTrue(collect($entries)->contains('id', $replacement->id));

            return true;
        }));
    }

    /** @return array{Branch, User, Subscription} */
    private function account(bool $latePermission = true): array
    {
        $this->travelTo('2026-10-21 08:00:00');
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->for($branch)->create();
        $collector->permissions()->sync(Permission::idsFor([
            PermissionKey::RecordCollections, PermissionKey::RecordCashPayments,
            PermissionKey::ViewSubscriptions, PermissionKey::ViewCollections,
            ...($latePermission ? [PermissionKey::RecordLatePayments] : []),
        ]));
        $subscription = Subscription::factory()->for($branch)->create();
        SubscriptionTransaction::factory()->for($subscription)->create([
            'amount' => '350.00', 'created_at' => '2026-10-09 08:00:00',
        ]);

        return [$branch, $collector, $subscription];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['late_payment' => true, 'paid_on' => '2026-10-10', 'late_reason' => 'نسيان إدخال الوصل',
            'late_payment_token' => Str::uuid()->toString(), 'amount' => '100', 'currency' => 'ILS',
            'payment_method' => 'cash', 'manual_voucher_number' => 'OLD-123'];
    }

    public function test_payment_reduces_current_balance_once_and_preserves_historical_ledger_rows(): void
    {
        [, $collector, $subscription] = $this->account();
        $old = $subscription->transactions()->sole()->getAttributes();
        $payload = [...$this->payload(), 'created_at' => '2026-10-10 08:00:00'];
        $this->archivedPayment($collector, $subscription, $payload);
        $payment = $subscription->transactions()->where('type', 'payment')->sole();
        $this->assertTrue($payment->created_at->equalTo(now()));
        $this->assertSame('2026-10-10', $payment->paid_on->toDateString());
        $this->assertSame('نسيان إدخال الوصل', $payment->late_reason);
        $this->assertEquals(250, $subscription->fresh()->balance());
        $this->assertSame($old, SubscriptionTransaction::findOrFail($old['id'])->getAttributes());
        $this->archivedPayment($collector, $subscription, $payload);
        $this->assertDatabaseCount('subscription_transactions', 2);
        $this->assertEquals(250, $subscription->fresh()->balance());
        $this->get(route('subscriptions.statement', $subscription))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.balance', '250.00')->where('entries.0.balance', '350.00')
            ->where('entries.1.balance', '250.00')->where('entries.1.paidOn', '2026-10-10')
            ->where('entries.1.lateReason', 'نسيان إدخال الوصل')->where('canRecordLatePayment', false));
    }

    public function test_late_cash_enters_the_current_open_box_without_touching_the_closed_box(): void
    {
        [$branch, $collector, $subscription] = $this->account();
        $head = User::factory()->branchAdmin()->for($branch)->create();
        $this->travelTo('2026-10-11 08:00:00');
        $this->actingAs($head)->post(route('payments.cash-boxes.actions', ['action' => 'start']), ['branch_id' => $branch->id])->assertSessionHasNoErrors();
        $oldPayment = SubscriptionTransaction::recordPayment($subscription, $collector, ['amount' => '50', 'currency' => 'ILS', 'payment_method' => 'cash']);
        $oldBox = $oldPayment->boxEntry->box;
        $this->post(route('payments.cash-boxes.actions', ['action' => 'receive', 'record' => $oldBox->id]), ['branch_id' => $branch->id])->assertSessionHasNoErrors();
        $oldBoxAttributes = $oldBox->fresh()->getAttributes();
        $oldPaymentAttributes = $oldPayment->fresh()->getAttributes();
        $this->travelTo('2026-10-21 08:00:00');
        $this->archivedPayment($collector, $subscription, $this->payload());
        $new = $subscription->transactions()->whereNotNull('paid_on')->sole();
        $this->assertSame('open', $new->boxEntry->box->status);
        $this->assertNotSame($oldBox->id, $new->boxEntry->cash_box_id);
        $this->assertTrue($new->boxEntry->occurred_at->equalTo(now()));
        $this->assertSame('100.00', $new->boxEntry->amount);
        $this->assertSame($oldBoxAttributes, $oldBox->fresh()->getAttributes());
        $this->assertSame($oldPaymentAttributes, $oldPayment->fresh()->getAttributes());
        $this->assertDatabaseCount('cash_box_payments', 2);
    }

    public function test_default_ledger_period_stays_on_recording_date_and_payment_date_filter_is_explicit(): void
    {
        [, $collector, $subscription] = $this->account();
        $this->archivedPayment($collector, $subscription, $this->payload());
        $period = ['period' => 'custom', 'from' => '2026-10-10', 'to' => '2026-10-10'];
        $this->get(route('ledger.index', $period))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('dateBasis', 'recorded')->where('entries.total', 0)->where('summary.collected', 0));
        $this->get(route('ledger.index', [...$period, 'date_basis' => 'paid']))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries.total', 1)->where('summary.collected', 100)
            ->where('entries.data.0.paidOn', '2026-10-10')->where('entries.data.0.day', '2026-10-21'));
        $this->get(route('ledger.index', ['period' => 'today']))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries.total', 1)->where('summary.collected', 100));
    }

    public function test_retired_late_permission_cannot_enable_registration_and_branch_and_method_boundaries_still_apply(): void
    {
        [, $collector, $subscription] = $this->account(false);
        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), $this->payload())->assertSessionHasErrors('late_payment');
        $collector->permissions()->attach(Permission::idsFor([PermissionKey::RecordLatePayments]));
        $this->actingAs($collector->fresh());
        $this->post(route('subscriptions.payments.store', Subscription::factory()->create()), $this->payload())->assertForbidden();
        $this->post(route('subscriptions.payments.store', $subscription), [...$this->payload(), 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'أحمد'])->assertSessionHasErrors('payment_method');
        $this->assertDatabaseCount('subscription_transactions', 1);
    }

    #[TestWith(['2026-10-21'])]
    #[TestWith(['2026-10-22'])]
    #[TestWith(['not-a-date'])]
    #[TestWith([''])]
    public function test_old_date_inputs_cannot_reenable_late_registration(string $date): void
    {
        [, $collector, $subscription] = $this->account();
        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), [...$this->payload(), 'paid_on' => $date])->assertSessionHasErrors('late_payment');
        $this->assertDatabaseCount('subscription_transactions', 1);
        $this->assertEquals(350, $subscription->fresh()->balance());
    }

    public function test_retry_after_settling_the_account_does_not_demand_overpayment_confirmation_or_charge_twice(): void
    {
        [, $collector, $subscription] = $this->account();
        $payload = [...$this->payload(), 'amount' => '350'];
        $this->archivedPayment($collector, $subscription, $payload);
        $this->archivedPayment($collector, $subscription, $payload);
        $this->assertEquals(0, $subscription->fresh()->balance());
        $this->assertDatabaseCount('subscription_transactions', 2);
    }

    public function test_late_transfer_preserves_bank_details_and_both_dates_for_auditors(): void
    {
        [, $collector, $subscription] = $this->account();
        $collector->permissions()->attach(Permission::idsFor([PermissionKey::RecordTransferPayments]));
        $payload = [...$this->payload(), 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_bank_name' => 'بنك القدس', 'sender_name' => 'أحمد', 'reference_number' => 'BANK-OLD-10'];
        $this->archivedPayment($collector, $subscription, $payload);
        $payment = $subscription->transactions()->where('type', 'payment')->sole();
        $details = $payment->ledgerDetails();
        $this->assertSame('2026-10-10', $details['paidOn']);
        $this->assertSame('2026-10-21 11:00', $details['date']);
        $this->assertSame('بنك فلسطين', $details['bankName']);
        $this->assertSame('بنك القدس', $details['senderBankName']);
        $this->assertSame('BANK-OLD-10', $details['referenceNumber']);
        $this->assertSame('نسيان إدخال الوصل', $details['lateReason']);
        $this->get(route('subscriptions.payments.receipt', [$subscription, $payment]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('receipt.paidOn', '2026-10-10')->where('receipt.date', '2026-10-21')->where('receipt.lateReason', 'نسيان إدخال الوصل'));
    }

    public function test_old_registration_fields_are_rejected_without_recording_a_payment(): void
    {
        [, $collector, $subscription] = $this->account();
        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), $this->payload())
            ->assertSessionHasErrors(['late_payment', 'paid_on', 'late_reason', 'late_payment_token']);
        $this->assertDatabaseCount('subscription_transactions', 1);
        $this->assertEquals(350, $subscription->fresh()->balance());
    }

    /** Existing archived payments retain their audit history after this feature is retired.
     * @param  array<string, mixed>  $payload
     */
    private function archivedPayment(User $collector, Subscription $subscription, array $payload): SubscriptionTransaction
    {
        $this->actingAs($collector);

        return SubscriptionTransaction::recordPayment($subscription, $collector, $payload);
    }
}
