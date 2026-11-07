<?php

namespace Tests\Feature;

use App\Enums\CashBoxType;
use App\Enums\ChargeType;
use App\Enums\PermissionKey;
use App\Models\MobileAccessToken;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\TransactionDeletion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OpenBoxInactivePaymentsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, User, Subscription} */
    private function staffAndSubscription(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(10, 0));
        $head = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create();
        $collector = User::factory()->collector()->for($head->branch)->create();
        $subscription = Subscription::factory()->for($head->branch)->create();

        return [$head, $collector, $subscription];
    }

    private function recordEntry(User $collector, Subscription $subscription, CashBoxType $type, string $amount): SubscriptionTransaction
    {
        return $type === CashBoxType::Clearing
            ? SubscriptionTransaction::recordClearing($subscription, $collector, $amount, 'خدمة مولد')
            : SubscriptionTransaction::recordPayment($subscription, $collector, [
                'amount' => $amount, 'currency' => 'ILS', 'payment_method' => $type->value,
                'bank_name' => 'بنك فلسطين', 'reference_number' => 'BOX-'.Str::ulid(),
            ]);
    }

    #[TestWith(['bank_transfer', 'refund', 'open'])]
    #[TestWith(['bank_transfer', 'refund', 'pending'])]
    #[TestWith(['cash', 'refund', 'open'])]
    #[TestWith(['cash', 'refund', 'pending'])]
    #[TestWith(['clearing', 'cancel', 'open'])]
    #[TestWith(['clearing', 'cancel', 'pending'])]
    public function test_inactive_entries_disappear_from_open_box_lists_counts_and_exports_but_stay_in_the_statement(string $type, string $action, string $boxStatus): void
    {
        [$head, $collector, $subscription] = $this->staffAndSubscription();
        $removed = $this->recordEntry($collector, $subscription, CashBoxType::from($type), '100');
        $active = $this->recordEntry($collector, $subscription, CashBoxType::from($type), '25');
        $box = $removed->boxEntry->box;
        $box->update(['status' => $boxStatus]);

        $this->actingAs($head)->post(route('subscriptions.transactions.actions.store', [$subscription, $removed]), [
            'action' => $action, 'correction_reason' => 'other', 'correction_notes' => 'سُجّلت بالخطأ',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cash_box_payments', ['id' => $removed->boxEntry->id, 'subscription_transaction_id' => $removed->id]);
        $this->assertSame('25.00', $box->fresh()->totals()['ILS']);
        $this->get(route('payments.cash-boxes.entries'))->assertInertia(fn ($page) => $page
            ->where('entries.total', 1)->where('entries.data.0.id', $active->boxEntry->id)
            ->where('counts.all', 1)->where('counts.'.$type, 1)->where('tabs.0.count', 1));
        $this->get(route('payments.cash-boxes', ['place' => 'open']))->assertInertia(fn ($page) => $page
            ->has('boxes.0.payments', 1)->where('boxes.0.payments.0.subscription_transaction_id', $active->id));
        $this->get(route('subscriptions.statement', $subscription))->assertInertia(fn ($page) => $page
            ->has('entries', 3)->where('entries.0.id', $removed->id)->where('entries.1.id', $active->id));

        $this->actingAs($collector)->get(route('payments.summary'))->assertInertia(fn ($page) => $page
            ->where('entries.total', 1)->where('entries.data.0.id', $active->boxEntry->id)
            ->where('current.'.$type.'.paymentCount', 1)->where('current.'.$type.'.totals.ILS', '25.00'));
        $this->get(route('payments.cash-boxes.show', $box))->assertInertia(fn ($page) => $page
            ->where('box.paymentCount', 1)->where('box.totals.ILS', '25.00')
            ->where('entries.total', 1)->where('entries.data.0.id', $active->boxEntry->id));
        $csv = $this->get(route('payments.cash-boxes.show', [$box, 'format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringNotContainsString(',100,', $csv);
        $this->assertStringContainsString(',25,', $csv);

        $this->withToken(MobileAccessToken::issue($collector))->getJson(route('mobile.cash-boxes.index'))
            ->assertJsonCount(1, 'boxes.0.payments')
            ->assertJsonPath('boxes.0.payments.0.subscription_transaction_id', $active->id)
            ->assertJsonPath('boxes.0.totals.ILS', '25.00');
    }

    public function test_deleting_a_payment_removes_it_from_the_open_box_and_retains_the_deletion_audit(): void
    {
        [$head, $collector, $subscription] = $this->staffAndSubscription();
        $active = $this->recordEntry($collector, $subscription, CashBoxType::BankTransfer, '25');
        $removed = $this->recordEntry($collector, $subscription, CashBoxType::BankTransfer, '100');
        $box = $removed->boxEntry->box;

        $this->actingAs($head)->post(route('subscriptions.transactions.actions.store', [$subscription, $removed]), [
            'action' => 'delete', 'correction_notes' => 'دفعة مكررة',
        ])->assertSessionHasNoErrors();

        $this->assertModelMissing($removed);
        $this->assertDatabaseMissing('cash_box_payments', ['subscription_transaction_id' => $removed->id]);
        $this->assertSame($removed->id, TransactionDeletion::query()->sole()->transactions[0]['id']);
        $this->actingAs($collector)->get(route('payments.cash-boxes.show', $box))->assertInertia(fn ($page) => $page
            ->where('box.paymentCount', 1)->where('box.totals.ILS', '25.00')
            ->where('entries.total', 1)->where('entries.data.0.id', $active->boxEntry->id));
    }

    public function test_undoing_a_refund_restores_the_payment_to_its_original_open_box(): void
    {
        [$head, $collector, $subscription] = $this->staffAndSubscription();
        $payment = $this->recordEntry($collector, $subscription, CashBoxType::BankTransfer, '100');
        SubscriptionTransaction::recordCharge($subscription, $head, ChargeType::Penalty, '10', 'غرامة');
        $entry = $payment->boxEntry;

        $this->actingAs($head)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'refund',
        ])->assertSessionHasNoErrors();
        $this->get(route('payments.cash-boxes.entries'))->assertInertia(fn ($page) => $page
            ->where('entries.total', 0)->where('counts.all', 0)->where('tabs.0.count', 0));
        $this->get(route('payments.cash-boxes', ['place' => 'open']))->assertInertia(fn ($page) => $page->has('boxes', 0));
        $refund = SubscriptionTransaction::query()->where('type', SubscriptionTransaction::TYPE_REFUND)->sole();
        $this->post(route('subscriptions.transactions.actions.store', [$subscription, $refund]), [
            'action' => 'delete_reversal', 'correction_notes' => 'الإرجاع سُجّل بالخطأ',
        ])->assertSessionHasNoErrors();

        $this->assertSame(SubscriptionTransaction::STATUS_ACTIVE, $payment->refresh()->status);
        $this->assertDatabaseHas('cash_box_payments', ['id' => $entry->id, 'subscription_transaction_id' => $payment->id]);
        $this->assertSame('100.00', $entry->box->fresh()->totals()['ILS']);
        $this->get(route('payments.cash-boxes', ['place' => 'open']))->assertInertia(fn ($page) => $page
            ->has('boxes', 1)->where('boxes.0.id', $entry->cash_box_id)->has('boxes.0.payments', 1));
        $this->actingAs($collector)->get(route('payments.cash-boxes.show', $entry->box))->assertInertia(fn ($page) => $page
            ->where('entries.total', 1)->where('box.paymentCount', 1)->where('box.totals.ILS', '100.00'));
    }

    public function test_closed_entries_from_another_branch_never_leak_into_the_open_entry_list(): void
    {
        [$head, $collector, $subscription] = $this->staffAndSubscription();
        $local = $this->recordEntry($collector, $subscription, CashBoxType::Cash, '25');
        $foreignCollector = User::factory()->collector()->create();
        $foreignSubscription = Subscription::factory()->for($foreignCollector->branch)->create();
        $foreign = $this->recordEntry($foreignCollector, $foreignSubscription, CashBoxType::Cash, '100');
        $foreign->update(['status' => SubscriptionTransaction::STATUS_CANCELLED]);
        $foreign->boxEntry->box->update(['status' => 'received']);

        $this->actingAs($head)->get(route('payments.cash-boxes.entries'))->assertInertia(fn ($page) => $page
            ->where('entries.total', 1)->where('entries.data.0.id', $local->boxEntry->id));
    }

    #[TestWith(['financial_auditor'])]
    #[TestWith(['accountant'])]
    #[TestWith(['super_admin'])]
    public function test_auditor_review_excludes_cancelled_and_refunded_payments_after_the_box_is_closed(string $role): void
    {
        [$head, $collector, $subscription] = $this->staffAndSubscription();
        $auditor = User::factory()->withPermissions([PermissionKey::AuditClosings])->create(['role' => $role]);
        $cancelled = $this->recordEntry($collector, $subscription, CashBoxType::BankTransfer, '100');
        $refunded = $this->recordEntry($collector, $subscription, CashBoxType::BankTransfer, '22');
        $active = $this->recordEntry($collector, $subscription, CashBoxType::BankTransfer, '25');
        $box = $active->boxEntry->box;

        $this->actingAs($head)->delete(route('subscriptions.transactions.destroy', [$subscription, $cancelled]), [
            'correction_reason' => 'duplicate', 'correction_notes' => 'دفعة مكررة',
        ])->assertSessionHasNoErrors();
        $this->post(route('subscriptions.transactions.actions.store', [$subscription, $refunded]), [
            'action' => 'refund',
        ])->assertSessionHasNoErrors();
        $this->post(route('payments.cash-boxes.actions', ['action' => 'receive', 'record' => $box->id]), [
            'branch_id' => $head->branch_id,
        ])->assertSessionHasNoErrors();

        $this->actingAs($auditor)->get(route('payments.cash-boxes', ['branch_id' => $head->branch_id, 'place' => 'closed']))
            ->assertInertia(fn ($page) => $page
                ->where('boxes.0.id', $box->id)->has('boxes.0.payments', 1)
                ->where('boxes.0.payments.0.subscription_transaction_id', $active->id)
                ->where('boxes.0.auditSummary.total', 1)->where('periodAudit.transfers', 1));
        $this->get(route('subscriptions.statement', $subscription))->assertInertia(fn ($page) => $page
            ->has('entries', 5)->where('entries.0.id', $cancelled->id)->where('entries.1.id', $refunded->id));
        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cash_box_payments', ['id' => $cancelled->boxEntry->id]);
        $this->assertDatabaseHas('cash_box_payments', ['id' => $refunded->boxEntry->id]);
        $this->assertDatabaseMissing('bank_transfer_audits', ['cash_box_payment_id' => $cancelled->boxEntry->id]);
        $this->assertDatabaseMissing('bank_transfer_audits', ['cash_box_payment_id' => $refunded->boxEntry->id]);
        $this->assertDatabaseHas('bank_transfer_audits', ['cash_box_payment_id' => $active->boxEntry->id, 'outcome' => 'matched']);
        $this->assertSame('25.00', $box->fresh()->totals()['ILS']);
    }
}
