<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\MobileAccessToken;
use App\Models\Permission;
use App\Models\SplitPayment;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class BankReceivedDateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, Subscription} */
    private function account(): array
    {
        $this->travelTo('2026-10-24 08:00:00');
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->for($branch)->create();
        $collector->permissions()->sync(Permission::idsFor([
            PermissionKey::RecordCollections, PermissionKey::RecordCashPayments,
            PermissionKey::RecordTransferPayments, PermissionKey::ViewSubscriptions,
            PermissionKey::ViewCollections,
        ]));
        $subscription = Subscription::factory()->for($branch)->create();
        SubscriptionTransaction::factory()->for($subscription)->create(['amount' => '1000.00', 'created_at' => '2026-10-20 08:00:00']);

        return [$collector, $subscription];
    }

    /** @return array<string, mixed> */
    private function payment(): array
    {
        return ['amount' => '100', 'currency' => 'ILS', 'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك فلسطين', 'sender_name' => 'محمد', 'reference_number' => 'BANK-DATE'];
    }

    #[TestWith([null])]
    #[TestWith(['2026-10-23'])]
    #[TestWith(['2026-10-24'])]
    public function test_optional_bank_date_does_not_change_recording_time_balances_or_box_time(?string $date): void
    {
        [$collector, $subscription] = $this->account();
        $oldLine = $subscription->transactions()->sole()->getAttributes();
        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), [...$this->payment(), 'bank_received_on' => $date, 'created_at' => '2026-10-01'])->assertSessionHasNoErrors();
        $payment = $subscription->transactions()->where('type', 'payment')->sole();

        $this->assertSame($date, $payment->bank_received_on?->toDateString());
        $this->assertNull($payment->paid_on);
        $this->assertTrue($payment->created_at->equalTo(now()));
        $this->assertTrue($payment->boxEntry->occurred_at->equalTo(now()));
        $this->assertSame(900.0, $subscription->fresh()->balance());
        $this->assertSame($oldLine, SubscriptionTransaction::findOrFail($oldLine['id'])->getAttributes());
        $this->assertSame($date, $payment->ledgerDetails()['bankReceivedOn']);
        $this->get(route('subscriptions.statement', $subscription))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canRecordLatePayment', false)->where('entries.1.bankReceivedOn', $date));
        $this->get(route('subscriptions.payments.receipt', [$subscription, $payment]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('receipt.date', '2026-10-24')->where('receipt.bankReceivedOn', $date));
    }

    #[TestWith(['2026-10-25'])]
    #[TestWith(['2026-02-30'])]
    #[TestWith(['not-a-date'])]
    public function test_future_or_invalid_bank_dates_cannot_record_a_payment(string $date): void
    {
        [$collector, $subscription] = $this->account();
        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), [...$this->payment(), 'bank_received_on' => $date])->assertSessionHasErrors('bank_received_on');
        $this->assertDatabaseCount('subscription_transactions', 1);
        $this->assertSame(1000.0, $subscription->fresh()->balance());
    }

    public function test_cash_never_stores_a_bank_date_and_payment_permissions_still_apply(): void
    {
        [$collector, $subscription] = $this->account();
        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), [...$this->payment(), 'payment_method' => 'cash', 'bank_received_on' => '2026-10-23'])->assertSessionHasNoErrors();
        $this->assertNull($subscription->transactions()->where('type', 'payment')->sole()->bank_received_on);
        $collector->permissions()->sync(Permission::idsFor([PermissionKey::RecordCollections, PermissionKey::RecordCashPayments]));
        $this->actingAs($collector->fresh())->post(route('subscriptions.payments.store', $subscription), [...$this->payment(), 'bank_received_on' => '2026-10-23'])->assertSessionHasErrors('payment_method');
        $this->assertSame(900.0, $subscription->fresh()->balance());
    }

    public function test_mobile_saves_and_returns_the_bank_date_and_retries_the_same_operation_once(): void
    {
        [$collector, $subscription] = $this->account();
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));
        $data = [...$this->payment(), 'subscription_id' => $subscription->id, 'bank_received_on' => '2026-10-23',
            'mobile_operation_id' => (string) Str::uuid(), 'collector_confirmed' => true];
        $first = $this->postJson(route('mobile.collections.store'), $data)->assertCreated()->assertJsonPath('bank_received_on', '2026-10-23')->json('id');
        $this->postJson(route('mobile.collections.store'), [...$data, 'bank_received_on' => '2026-10-22'])->assertCreated()->assertJsonPath('id', $first)->assertJsonPath('bank_received_on', '2026-10-23');
        $this->assertDatabaseCount('subscription_transactions', 2);
        $this->getJson(route('mobile.collections.index'))->assertOk()->assertJsonPath('data.0.bank_received_on', '2026-10-23');
    }

    public function test_bank_date_can_be_amended_and_cleared_with_an_audit_trail_without_changing_money(): void
    {
        [$collector, $subscription] = $this->account();
        $payment = SubscriptionTransaction::recordPayment($subscription, $collector, [...$this->payment(), 'bank_received_on' => '2026-10-23']);
        $head = User::factory()->branchAdmin()->for($collector->branch)->create();
        $originalTime = $payment->created_at->toDateTimeString();
        $boxEntry = $payment->boxEntry->getAttributes();
        $data = ['bank_name' => 'بنك فلسطين', 'bank_received_on' => '2026-10-22', 'amendment_reason' => 'تصحيح تاريخ إشعار البنك'];
        $this->actingAs($head)->patch(route('subscriptions.transactions.amend', [$subscription, $payment]), $data)->assertSessionHasNoErrors();
        $this->assertSame(['2026-10-23', '2026-10-22'], $payment->amendments()->sole()->changes['bank_received_on']);
        $this->patch(route('subscriptions.transactions.amend', [$subscription, $payment]), [...$data, 'bank_received_on' => null])->assertSessionHasNoErrors();
        $this->assertNull($payment->fresh()->bank_received_on);
        $this->assertSame($originalTime, $payment->created_at->toDateTimeString());
        $this->assertSame($boxEntry, $payment->boxEntry->fresh()->getAttributes());
        $this->assertSame(900.0, $subscription->fresh()->balance());
    }

    public function test_a_split_transfer_and_all_its_parts_share_the_bank_date_and_audited_date_corrections(): void
    {
        [$collector, $subscription] = $this->account();
        $other = Subscription::factory()->for($collector->branch)->create();
        $data = ['total_amount' => '100', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'محمد',
            'reference_number' => 'SPLIT-DATE', 'bank_received_on' => '2026-10-23', 'confirm_overpayment' => true,
            'parts' => [['subscription_id' => $subscription->id, 'amount' => '60'], ['subscription_id' => $other->id, 'amount' => '40']]];
        $this->actingAs($collector)->post(route('payments.split.store'), $data)->assertSessionHasNoErrors();
        $split = SplitPayment::query()->sole();
        $this->assertSame('2026-10-23', $split->bank_received_on->toDateString());
        $this->assertSame(['2026-10-23', '2026-10-23'], $split->payments->map(fn (SubscriptionTransaction $part) => $part->bank_received_on->toDateString())->all());
        $head = User::factory()->branchAdmin()->for($collector->branch)->create();
        $revision = $split->editingDetails($head)['revision'];
        $this->actingAs($head)->getJson(route('split-payments.edit', $split))->assertOk()->assertJsonPath('bank_received_on', '2026-10-23');
        $edit = ['bank_name' => 'بنك فلسطين', 'sender_name' => 'محمد', 'reference_number' => 'SPLIT-DATE',
            'bank_received_on' => null, 'amendment_reason' => 'لا يوجد تأكيد من البنك', 'revision' => $revision];
        $this->actingAs($head)->patch(route('split-payments.update', $split), $edit)->assertSessionHasNoErrors();
        $this->assertNull($split->fresh()->bank_received_on);
        $this->assertSame(0, $split->payments()->whereNotNull('bank_received_on')->count());
        $this->assertDatabaseCount('transaction_amendments', 2);
        $this->patch(route('split-payments.update', $split), [...$edit, 'bank_received_on' => '2026-10-22'])->assertSessionHasErrors('action');
        $this->assertSame(940.0, $subscription->fresh()->balance());
    }

    public function test_amount_correction_keeps_the_bank_date_when_omitted_and_allows_explicit_clearing(): void
    {
        [$collector, $subscription] = $this->account();
        $payment = SubscriptionTransaction::recordPayment($subscription, $collector, [...$this->payment(), 'bank_received_on' => '2026-10-23']);
        $head = User::factory()->branchAdmin()->for($collector->branch)->create();
        $correction = [...$this->payment(), 'amount' => '110', 'correction_reason' => 'wrong_amount', 'correction_notes' => 'تصحيح قيمة الدفعة'];
        $this->actingAs($head)->put(route('subscriptions.transactions.update', [$subscription, $payment]), $correction)->assertSessionHasNoErrors();
        $replacement = $payment->fresh()->correction;
        $this->assertSame('2026-10-23', $replacement->bank_received_on->toDateString());
        $this->assertSame('2026-10-23', $payment->bank_received_on->toDateString());
        $this->put(route('subscriptions.transactions.update', [$subscription, $replacement]), [...$correction, 'amount' => '120', 'bank_received_on' => null])->assertSessionHasNoErrors();
        $this->assertNull($replacement->fresh()->correction->bank_received_on);
        $this->assertSame(880.0, $subscription->fresh()->balance());
    }
}
