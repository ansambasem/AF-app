<?php

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\ClosingStatus;
use App\Enums\CorrectionReason;
use App\Enums\DiscountMethod;
use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\Closing;
use App\Models\ClosingPayment;
use App\Models\MeterReading;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\TransactionDeletion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTransactionActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_statement_returns_canonical_available_actions_in_the_required_order(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $invoice = SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '50', 'غرامة');
        $payment = SubscriptionTransaction::recordPayment($subscription, $actor, [
            'amount' => '20',
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ]);

        $response = $this->actingAs($actor)->get(route('subscriptions.statement', $subscription));

        $response->assertInertia(fn ($page) => $page
            ->where('entries.0.id', $invoice->id)
            ->where('entries.0.available_actions', ['cancel'])
            ->where('entries.1.id', $payment->id)
            ->where('entries.1.available_actions', ['edit_metadata', 'delete']));
    }

    public function test_edit_changes_only_the_last_invoice_amount_and_recomputes_its_balance(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $invoice = SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '50', 'غرامة');

        $response = $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $invoice]), [
            'action' => 'edit',
            'amount' => '75',
            'amendment_reason' => 'المبلغ الصحيح في المستند',
        ]);

        $response->assertSessionHasNoErrors()->assertSessionHas('status', 'transaction-edit');
        $this->assertDatabaseCount('subscription_transactions', 1);
        $this->assertSame('75.00', $invoice->refresh()->amount);
        $this->assertSame('75.00', $invoice->balance_after);
        $this->assertSame(75.0, $subscription->balance());
        $this->assertDatabaseHas('transaction_amendments', [
            'transaction_id' => $invoice->id,
            'user_id' => $actor->id,
            'reason' => 'المبلغ الصحيح في المستند',
        ]);
    }

    public function test_an_edited_charge_cannot_be_more_than_the_other_forms_allow(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $invoice = SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '50', 'غرامة');
        $edit = fn (string $amount) => $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $invoice]), [
            'action' => 'edit',
            'amount' => $amount,
            'amendment_reason' => 'المبلغ الصحيح في المستند',
        ]);

        $edit('99999999')->assertSessionHasErrors('amount');
        $edit('1000000.01')->assertSessionHasErrors('amount');
        $this->assertSame('50.00', $invoice->fresh()->amount);

        $edit('1000000')->assertSessionHasNoErrors();
        $this->assertSame('1000000.00', $invoice->fresh()->amount);
    }

    public function test_a_refund_always_returns_the_whole_payment_and_links_both_rows(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $payment = SubscriptionTransaction::recordPayment($subscription, $actor, [
            'amount' => '50',
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ]);
        SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '10', 'غرامة');

        $response = $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'refund',
            'correction_notes' => 'المبلغ سُجّل خطأ، تُسجّل الدفعة الصحيحة بعده',
        ]);

        $response->assertSessionHasNoErrors();
        $refund = SubscriptionTransaction::query()->where('type', 'refund')->sole();
        $this->assertSame($payment->id, $refund->reference_transaction_id);
        $this->assertSame('50.00', $refund->amount);
        $this->assertSame('10.00', $refund->balance_after);
        $this->assertSame(SubscriptionTransaction::STATUS_LINKED_CANCELLATION, $payment->refresh()->status);
        $this->assertSame('المبلغ سُجّل خطأ، تُسجّل الدفعة الصحيحة بعده', $payment->cancellation_notes);

        $this->actingAs($actor)->get(route('subscriptions.statement', $subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.0.linkedReversal.id', $refund->id)
                ->where('entries.2.reverses.id', $payment->id));
    }

    public function test_a_refund_cannot_be_for_part_of_a_payment(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $payment = SubscriptionTransaction::recordPayment($subscription, $actor, ['amount' => '50', 'currency' => 'ILS', 'payment_method' => 'cash']);
        SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '10', 'غرامة');

        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'refund',
            'amount' => '20',
        ])->assertSessionHasErrors('amount');

        $this->assertDatabaseMissing('subscription_transactions', ['type' => SubscriptionTransaction::TYPE_REFUND]);
        $this->assertSame(SubscriptionTransaction::STATUS_ACTIVE, $payment->refresh()->status);
    }

    public function test_a_payment_partly_refunded_before_refunds_only_what_is_left(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $payment = SubscriptionTransaction::recordPayment($subscription, $actor, ['amount' => '50', 'currency' => 'ILS', 'payment_method' => 'cash']);
        SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '10', 'غرامة');
        $this->legacyPartialRefund($payment, $actor, '20.00');

        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'refund',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['20.00', '30.00'], SubscriptionTransaction::query()->where('type', 'refund')->orderBy('id')->pluck('amount')->all());
        $this->assertSame(SubscriptionTransaction::STATUS_LINKED_CANCELLATION, $payment->refresh()->status);
        $this->assertSame(10.0, $subscription->balance());
    }

    public function test_cancel_appends_a_linked_cancellation_and_marks_the_original_cancelled(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $invoice = SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '50', 'غرامة');
        SubscriptionTransaction::recordPayment($subscription, $actor, [
            'amount' => '20',
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ]);

        $response = $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $invoice]), [
            'action' => 'cancel',
        ]);

        $response->assertSessionHasNoErrors();
        $cancellation = SubscriptionTransaction::query()->where('type', 'cancellation')->sole();
        $this->assertSame('cancelled', $invoice->refresh()->status);
        $this->assertSame($invoice->id, $cancellation->reference_transaction_id);
        $this->assertSame('-50.00', $cancellation->amount);
        $this->assertSame('-20.00', $cancellation->balance_after);
    }

    public function test_delete_hard_deletes_only_the_last_transaction(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $payment = SubscriptionTransaction::recordPayment($subscription, $actor, [
            'amount' => '50',
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ]);

        $response = $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'delete',
            'correction_notes' => 'دفعة مكررة',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertModelMissing($payment);
        $this->assertSame(0.0, $subscription->balance());
        $deletion = TransactionDeletion::sole();
        $this->assertSame([$subscription->id, $actor->id, 'delete', 'دفعة مكررة'], [$deletion->subscription_id, $deletion->user_id, $deletion->action, $deletion->reason]);
        $this->assertSame([$payment->id], array_column($deletion->transactions, 'id'));
    }

    public function test_statement_does_not_offer_edit_metadata_for_a_discount_without_payment_details(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '50', 'غرامة');
        SubscriptionTransaction::recordDiscount($subscription, $actor, DiscountMethod::Shekel, '20', 'البيان الأصلي');

        $response = $this->actingAs($actor)->get(route('subscriptions.statement', $subscription));

        $response->assertInertia(fn ($page) => $page->where('entries.1.available_actions', ['delete']));
    }

    public function test_server_rejects_edit_metadata_for_a_discount_without_payment_details(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '50', 'غرامة');
        $discount = SubscriptionTransaction::recordDiscount($subscription, $actor, DiscountMethod::Shekel, '20', 'البيان الأصلي');

        $response = $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $discount]), [
            'action' => 'edit_metadata',
            'notes' => 'بيان جديد',
            'amendment_reason' => 'محاولة غير مسموحة',
        ]);

        $response->assertSessionHasErrors(['action' => 'هذا الإجراء غير مسموح لهذه الحركة.']);
        $this->assertSame('البيان الأصلي', $discount->refresh()->notes);
        $this->assertDatabaseCount('transaction_amendments', 0);
        $this->assertSame(30.0, $subscription->balance());
    }

    public function test_editing_details_through_the_actions_route_follows_the_same_bank_rules_as_the_amendment_form(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::AmendTransactionDetails])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '100', 'غرامة');
        $cash = SubscriptionTransaction::recordPayment($subscription, $actor, ['amount' => '20', 'currency' => 'ILS', 'payment_method' => 'cash']);
        $transfer = SubscriptionTransaction::recordPayment($subscription, $actor, [
            'amount' => '30', 'currency' => 'ILS', 'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك فلسطين', 'sender_name' => 'Ahmad', 'reference_number' => 'TR-1',
        ]);
        $edit = fn (SubscriptionTransaction $line, array $fields) => $this->actingAs($actor)->post(
            route('subscriptions.transactions.actions.store', [$subscription, $line]),
            ['action' => 'edit_metadata', 'amendment_reason' => 'تصحيح', ...$fields],
        );

        // A cash payment has no bank, whatever name is sent.
        $edit($cash, ['bank_name' => 'بنك وهمي', 'notes' => 'ملاحظة'])->assertSessionHasErrors('bank_name');
        $edit($cash, ['sender_name' => 'Someone', 'notes' => 'ملاحظة'])->assertSessionHasErrors('sender_name');
        $this->assertNull($cash->refresh()->bank_name);
        $edit($cash, ['notes' => 'ملاحظة'])->assertSessionHasNoErrors();
        $this->assertSame('ملاحظة', $cash->refresh()->notes);

        // A transfer's bank is one of the transfer banks, and is never left out.
        $edit($transfer, ['bank_name' => 'بنك وهمي'])->assertSessionHasErrors('bank_name');
        $edit($transfer, ['notes' => 'بلا بنك'])->assertSessionHasErrors('bank_name');
        $edit($transfer, ['bank_name' => 'جوال باي', 'sender_bank_name' => 'بنك القدس'])->assertSessionHasNoErrors();
        $this->assertSame(['جوال باي', 'بنك القدس'], [$transfer->refresh()->bank_name, $transfer->sender_bank_name]);
        $edit($transfer, ['bank_name' => 'جوال باي', 'sender_bank_name' => 'بنك وهمي'])->assertSessionHasErrors('sender_bank_name');
    }

    public function test_full_refund_marks_the_original_as_linked_cancellation(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $payment = SubscriptionTransaction::recordPayment($subscription, $actor, [
            'amount' => '50',
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ]);
        SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '10', 'غرامة');

        $response = $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'refund',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('linked_cancellation', $payment->refresh()->status);
        $this->assertSame(10.0, $subscription->balance());
    }

    public function test_non_last_discount_is_cancelled_with_a_type_specific_cancellation_instead_of_a_refund(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '50', 'غرامة');
        $discount = SubscriptionTransaction::recordDiscount($subscription, $actor, DiscountMethod::Shekel, '20', 'خصم اجتماعي');
        SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '5', 'غرامة لاحقة');

        $this->actingAs($actor)->get(route('subscriptions.statement', $subscription))
            ->assertInertia(fn ($page) => $page->where('entries.1.available_actions', ['cancel']));

        $response = $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $discount]), [
            'action' => 'cancel',
            'correction_notes' => 'أُلغي الخصم',
        ]);

        $response->assertSessionHasNoErrors();
        $cancellation = SubscriptionTransaction::query()->where('reference_transaction_id', $discount->id)->sole();
        $this->assertSame(SubscriptionTransaction::TYPE_CANCELLATION, $cancellation->type);
        $this->assertStringContainsString('إلغاء:', $cancellation->description());
        $this->assertDatabaseMissing('subscription_transactions', [
            'reference_transaction_id' => $discount->id,
            'type' => SubscriptionTransaction::TYPE_REFUND,
        ]);
    }

    public function test_fully_refunded_payment_offers_delete_refund_only_and_delete_linked_tree_actions(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $payment = SubscriptionTransaction::recordPayment($subscription, $actor, [
            'amount' => '50',
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ]);
        SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '10', 'غرامة');
        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'refund',
        ])->assertSessionHasNoErrors();
        $refund = SubscriptionTransaction::query()->where('type', SubscriptionTransaction::TYPE_REFUND)->sole();

        $this->actingAs($actor)->get(route('subscriptions.statement', $subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.0.available_actions', ['delete_tree'])
                ->where('entries.0.linkedReversals.0.id', $refund->id)
                ->where('entries.2.available_actions', ['delete_reversal']));
    }

    public function test_deleting_only_the_last_refund_reactivates_the_payment_and_rebalances_the_statement(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $payment = SubscriptionTransaction::recordPayment($subscription, $actor, [
            'amount' => '50',
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ]);
        $charge = SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '10', 'غرامة');
        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'refund',
        ]);
        $refund = SubscriptionTransaction::query()->where('type', SubscriptionTransaction::TYPE_REFUND)->sole();

        $response = $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $refund]), [
            'action' => 'delete_reversal',
            'correction_notes' => 'الإرجاع سُجل بالخطأ',
        ]);

        $response->assertSessionHasNoErrors()->assertSessionHas('status', 'transaction-delete_reversal');
        $this->assertModelMissing($refund);
        $this->assertSame(SubscriptionTransaction::STATUS_ACTIVE, $payment->refresh()->status);
        $this->assertNull($payment->cancelled_at);
        $this->assertSame('-40.00', $charge->refresh()->balance_after);
        $this->assertSame(-40.0, $subscription->balance());
    }

    public function test_deleting_a_fully_refunded_tree_removes_both_sides_and_rebalances_later_transactions(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $payment = SubscriptionTransaction::recordPayment($subscription, $actor, [
            'amount' => '50',
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ]);
        $firstCharge = SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '10', 'غرامة أولى');
        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'refund',
        ]);
        $refund = SubscriptionTransaction::query()->where('type', SubscriptionTransaction::TYPE_REFUND)->sole();
        $lastCharge = SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '20', 'غرامة لاحقة');

        $response = $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'delete_tree',
            'correction_notes' => 'الدفعة والإرجاع مكرران',
        ]);

        $response->assertSessionHasNoErrors()->assertSessionHas('status', 'transaction-delete_tree');
        $this->assertModelMissing($payment);
        $this->assertModelMissing($refund);
        $this->assertSame('10.00', $firstCharge->refresh()->balance_after);
        $this->assertSame('30.00', $lastCharge->refresh()->balance_after);
        $this->assertSame(30.0, $subscription->balance());
        $deletion = TransactionDeletion::sole();
        $this->assertSame(['delete_tree', 'الدفعة والإرجاع مكرران'], [$deletion->action, $deletion->reason]);
        $this->assertSame([$payment->id, $refund->id], array_column($deletion->transactions, 'id'));
    }

    public function test_partial_refund_cannot_delete_the_original_and_linked_tree(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $payment = SubscriptionTransaction::recordPayment($subscription, $actor, [
            'amount' => '50',
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ]);
        SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '10', 'غرامة');
        // Partial refunds can't be made any more, but older ones stay on the books.
        $this->legacyPartialRefund($payment, $actor, '20.00');

        $response = $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'delete_tree',
            'correction_notes' => 'محاولة حذف جزئي',
        ]);

        $response->assertSessionHasErrors(['action' => 'هذا الإجراء غير مسموح لهذه الحركة.']);
        $this->assertModelExists($payment);
        $this->assertDatabaseHas('subscription_transactions', [
            'reference_transaction_id' => $payment->id,
            'type' => SubscriptionTransaction::TYPE_REFUND,
            'amount' => '20.00',
        ]);
    }

    public function test_permanent_actions_and_metadata_edits_are_unavailable_for_a_payment_in_a_closed_day(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $payment = SubscriptionTransaction::recordPayment($subscription, $actor, [
            'amount' => '50',
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ]);
        $closing = Closing::factory()->submitted()->create(['branch_id' => $branch->id]);
        ClosingPayment::create(['closing_id' => $closing->id, 'subscription_transaction_id' => $payment->id]);

        $this->actingAs($actor)->get(route('subscriptions.statement', $subscription))
            ->assertInertia(fn ($page) => $page->where('entries.0.available_actions', []));

        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'delete',
            'correction_notes' => 'لا يجب الحذف',
        ])->assertSessionHasErrors(['action' => 'هذا الإجراء غير مسموح لهذه الحركة.']);

        $this->assertModelExists($payment);
    }

    public function test_deleting_the_last_payment_takes_it_off_a_draft_closing(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $payment = SubscriptionTransaction::recordPayment($subscription, $actor, [
            'amount' => '50',
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ]);
        $closing = Closing::factory()->create(['branch_id' => $branch->id]);
        $closingLine = ClosingPayment::create(['closing_id' => $closing->id, 'subscription_transaction_id' => $payment->id]);

        $response = $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'delete',
            'correction_notes' => 'دفعة مكررة',
        ]);

        $response->assertSessionHasNoErrors()->assertSessionHas('status', 'transaction-delete');
        $this->assertModelMissing($payment);
        $this->assertModelMissing($closingLine);
        $this->assertModelExists($closing);
    }

    public function test_deleting_a_refunded_payment_tree_takes_it_off_a_returned_closing(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $payment = SubscriptionTransaction::recordPayment($subscription, $actor, [
            'amount' => '50',
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ]);
        $closing = Closing::factory()->create(['branch_id' => $branch->id, 'status' => ClosingStatus::Returned]);
        $closingLine = ClosingPayment::create(['closing_id' => $closing->id, 'subscription_transaction_id' => $payment->id]);
        SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '10', 'غرامة');
        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'refund',
        ]);
        $refund = SubscriptionTransaction::query()->where('type', SubscriptionTransaction::TYPE_REFUND)->sole();

        $response = $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'delete_tree',
            'correction_notes' => 'الدفعة والإرجاع مكرران',
        ]);

        $response->assertSessionHasNoErrors()->assertSessionHas('status', 'transaction-delete_tree');
        $this->assertModelMissing($payment);
        $this->assertModelMissing($refund);
        $this->assertModelMissing($closingLine);
    }

    public function test_cancelling_a_weekly_reading_cancels_its_standing_discount_with_it(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        [$charge, $discount] = $this->billedReadingWithDiscount($subscription, $actor);
        SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '10', 'غرامة');

        $this->actingAs($actor)->get(route('subscriptions.statement', $subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.0.id', $charge->id)
                ->where('entries.0.readingDiscount', '30')
                ->where('entries.1.readingDiscount', null));

        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $charge]), [
            'action' => 'cancel',
            'correction_reason' => 'wrong_reading',
            'correction_notes' => 'قراءة مُدخلة بالخطأ',
        ])->assertSessionHasNoErrors();

        $discount->refresh();
        $this->assertNotNull($discount->cancelled_at);
        $this->assertSame('wrong_reading', $discount->cancellation_reason->value);
        $this->assertSame('قراءة مُدخلة بالخطأ', $discount->cancellation_notes);
        $this->assertSame('30.00', SubscriptionTransaction::query()->where('reverses_id', $discount->id)->sole()->amount);
        // Only the penalty is left owing: the reading and its discount are both gone from the balance.
        $this->assertSame(10.0, $subscription->balance());
    }

    public function test_deleting_a_weekly_reading_with_a_reason_reverses_its_standing_discount_with_it(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        [$charge, $discount] = $this->billedReadingWithDiscount($subscription, $actor);

        $this->actingAs($actor)
            ->delete(route('subscriptions.transactions.destroy', [$subscription, $charge]), ['correction_reason' => 'wrong_reading', 'correction_notes' => 'خطأ'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($discount->refresh()->cancelled_at);
        $this->assertSame(0.0, $subscription->balance());
    }

    public function test_a_weekly_reading_discount_cannot_be_cancelled_or_deleted_on_its_own(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->superAdmin()->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        [$charge, $discount] = $this->billedReadingWithDiscount($subscription, $actor);

        $this->actingAs($actor)->get(route('subscriptions.statement', $subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.1.id', $discount->id)
                ->where('entries.1.available_actions', [])
                ->where('entries.1.reading', null)
                ->where('entries.1.canDelete', false)
                ->where('entries.1.canForceDelete', false));

        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $discount]), [
            'action' => 'delete',
            'correction_notes' => 'خطأ',
        ])->assertSessionHasErrors(['action' => 'هذا الإجراء غير مسموح لهذه الحركة.']);
        $this->actingAs($actor)
            ->delete(route('subscriptions.transactions.destroy', [$subscription, $discount]), ['correction_reason' => 'wrong_reading', 'correction_notes' => 'خطأ'])
            ->assertForbidden();

        $this->assertNull($discount->refresh()->cancelled_at);
        $this->assertSame(53.4, $subscription->balance());
    }

    public function test_deleting_the_last_weekly_reading_deletes_its_standing_discount_with_it(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->superAdmin()->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '10', 'غرامة');
        [$charge, $discount] = $this->billedReadingWithDiscount($subscription, $actor);

        $this->actingAs($actor)->get(route('subscriptions.statement', $subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.1.id', $charge->id)
                ->where('entries.1.available_actions', ['delete'])
                ->where('entries.1.actionEffects.delete', '53.40'));

        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $charge]), [
            'action' => 'delete',
            'correction_notes' => 'قراءة مُدخلة بالخطأ',
        ])->assertSessionHasNoErrors();

        $this->assertModelMissing($charge);
        $this->assertModelMissing($discount);
        $this->assertSame(10.0, $subscription->balance());
        $this->assertCount(2, TransactionDeletion::sole()->transactions);
    }

    public function test_the_reversal_of_a_weekly_reading_discount_cannot_be_deleted_to_bring_the_discount_back(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->superAdmin()->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        [$charge, $discount] = $this->billedReadingWithDiscount($subscription, $actor);
        $charge->cancel($actor, CorrectionReason::WrongReading, 'خطأ');
        $discountReversal = SubscriptionTransaction::query()->where('reverses_id', $discount->id)->sole();

        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $discountReversal]), [
            'action' => 'delete_reversal',
            'correction_notes' => 'خطأ',
        ])->assertSessionHasErrors(['action' => 'هذا الإجراء غير مسموح لهذه الحركة.']);

        $this->assertNotNull($discount->refresh()->cancelled_at);
        $this->assertSame(0.0, $subscription->balance());
    }

    /**
     * A partial refund of the payment, as one was recorded before refunds
     * became whole-payment only.
     */
    private function legacyPartialRefund(SubscriptionTransaction $payment, User $actor, string $amount): SubscriptionTransaction
    {
        return $payment->subscription->transactions()->create([
            'recorded_by' => $actor->id,
            'reverses_id' => $payment->id,
            'reference_transaction_id' => $payment->id,
            'type' => SubscriptionTransaction::TYPE_REFUND,
            'status' => SubscriptionTransaction::STATUS_ACTIVE,
            'source_key' => 'refund:'.$payment->id.':legacy',
            'amount' => $amount,
            'currency' => $payment->currency,
            'currency_amount' => $amount,
            'exchange_rate' => $payment->exchange_rate,
            'payment_method' => $payment->payment_method,
        ]);
    }

    /**
     * An approved weekly reading billed 83.40 with a 30 standing discount beside it.
     *
     * @return array{0: SubscriptionTransaction, 1: SubscriptionTransaction}
     */
    private function billedReadingWithDiscount(Subscription $subscription, User $actor): array
    {
        $reading = MeterReading::factory()->approved()->for($subscription)->create();
        $charge = $subscription->transactions()->create([
            'recorded_by' => $actor->id,
            'meter_reading_id' => $reading->id,
            'type' => SubscriptionTransaction::TYPE_METER_READING,
            'source_key' => $reading->chargeSourceKey(),
            'amount' => '83.40',
            'currency_amount' => '83.40',
        ]);
        $discount = $subscription->transactions()->create([
            'recorded_by' => $actor->id,
            'meter_reading_id' => $reading->id,
            'type' => SubscriptionTransaction::TYPE_READING_DISCOUNT,
            'source_key' => $reading->discountSourceKey(),
            'amount' => '-30.00',
            'currency_amount' => '30.00',
            'discount_method' => DiscountMethod::Kilowatt,
            'discount_value' => '1',
        ]);

        return [$charge, $discount];
    }

    public function test_a_cancelled_reading_discount_cannot_be_deleted_with_its_cancellation(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        [$charge, $readingDiscount] = $this->billedReadingWithDiscount($subscription, $actor);
        $reading = $charge->meterReading;
        SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '10', 'غرامة');
        $charge->cancel($actor, CorrectionReason::WrongReading, 'خطأ');

        $this->actingAs($actor)->get(route('subscriptions.statement', $subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.1.id', $readingDiscount->id)
                ->where('entries.1.available_actions', []));

        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $readingDiscount]), [
            'action' => 'delete_tree',
            'correction_notes' => 'خصم ملغى',
        ])->assertSessionHasErrors(['action' => 'هذا الإجراء غير مسموح لهذه الحركة.']);

        $this->assertDatabaseHas('subscription_transactions', [
            'id' => $readingDiscount->id,
            'source_key' => $reading->discountSourceKey(),
        ]);
    }

    public function test_permanent_deletion_actions_require_an_audit_reason(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $payment = SubscriptionTransaction::recordPayment($subscription, $actor, [
            'amount' => '50',
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ]);

        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'delete',
        ])->assertSessionHasErrors('correction_notes');

        $this->assertModelExists($payment);
    }

    public function test_server_rejects_an_action_not_returned_in_available_actions(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->branchAdmin()->withPermissions([PermissionKey::ForceDeleteTransactions])->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $payment = SubscriptionTransaction::recordPayment($subscription, $actor, [
            'amount' => '50',
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ]);

        $response = $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'edit',
            'amount' => '75',
            'amendment_reason' => 'محاولة غير مسموحة',
        ]);

        $response->assertSessionHasErrors(['action' => 'هذا الإجراء غير مسموح لهذه الحركة.']);
        $this->assertSame('-50.00', $payment->refresh()->amount);
        $this->assertDatabaseCount('subscription_transactions', 1);
    }
}
