<?php

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\CorrectionReason;
use App\Enums\PermissionKey;
use App\Enums\TransactionAction;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\ClosingSetting;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BranchClosingWorkflow;
use App\Support\BranchSettings;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ClosedCashBoxPaymentLockTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Branch, User, User, Subscription, SubscriptionTransaction, CashBox} */
    private function payment(string $method = 'cash'): array
    {
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(10, 0));
        $branch = Branch::factory()->create();
        $head = User::factory()->branchAdmin()->for($branch)->withPermissions([
            PermissionKey::CorrectTransactions, PermissionKey::AmendTransactionDetails,
            PermissionKey::DeleteTransactions, PermissionKey::RefundPayments, PermissionKey::ForceDeleteTransactions,
        ])->create();
        $collector = User::factory()->collector()->for($branch)->create();
        $subscription = Subscription::factory()->for($branch)->create();
        $payment = SubscriptionTransaction::recordPayment($subscription, $collector, [
            'amount' => '100', 'currency' => 'ILS', 'payment_method' => $method,
            'bank_name' => 'بنك فلسطين', 'reference_number' => 'TRX-22', 'cash_box' => 'OLD-22',
        ]);

        return [$branch, $head, $collector, $subscription, $payment, $payment->boxEntry->box];
    }

    /** @return array<string, array{string, string}> */
    public static function closedActions(): array
    {
        $cases = [];

        foreach (['branchAdmin', 'superAdmin'] as $role) {
            foreach (TransactionAction::cases() as $action) {
                $cases[$role.' '.$action->value] = [$role, $action->value];
            }
        }

        return $cases;
    }

    #[DataProvider('closedActions')]
    public function test_closed_payment_actions_are_refused_for_admins_without_changing_history(string $role, string $action): void
    {
        [, $head, , $subscription, $payment, $box] = $this->payment();
        app(BranchClosingWorkflow::class)->receive($box, $head);
        SubscriptionTransaction::recordCharge($subscription, $head, ChargeType::Penalty, '25', null);
        $actor = $role === 'branchAdmin' ? $head : User::factory()->superAdmin()->create();
        $before = $payment->fresh()->getAttributes();
        $boxBefore = $box->fresh()->getAttributes();
        $entryBefore = $payment->boxEntry()->firstOrFail()->getAttributes();

        $this->assertSame([], $payment->availableActions($actor));
        foreach (['update', 'amend', 'delete', 'forceDelete'] as $ability) {
            $this->assertFalse($actor->can($ability, $payment));
        }

        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => $action, 'notes' => 'تغيير', 'amendment_reason' => 'تصحيح', 'correction_notes' => 'تصحيح',
            ...($action === 'edit' ? ['amount' => '1'] : []),
        ])->assertSessionHasErrors('action');

        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertSame($boxBefore, $box->fresh()->getAttributes());
        $this->assertSame($entryBefore, $payment->boxEntry()->firstOrFail()->getAttributes());
        $this->assertSame('100.00', $box->fresh()->totals()['ILS']);
        $this->assertSame('100.00', $box->fresh()->expected_counts['ILS']);
        $this->assertSame('100.00', $box->fresh()->head_counts['ILS']);
        $this->assertDatabaseCount('subscription_transactions', 2);
        $this->assertDatabaseCount('transaction_amendments', 0);
        $this->assertDatabaseCount('transaction_deletions', 0);
    }

    #[TestWith(['branchAdmin'])]
    #[TestWith(['superAdmin'])]
    public function test_legacy_routes_cannot_mutate_a_closed_payment_even_when_it_is_last(string $role): void
    {
        [, $head, , $subscription, $payment, $box] = $this->payment();
        app(BranchClosingWorkflow::class)->receive($box, $head);
        $actor = $role === 'branchAdmin' ? $head : User::factory()->superAdmin()->create();
        $before = $payment->fresh()->getAttributes();

        $this->actingAs($actor)->patch(route('subscriptions.transactions.amend', [$subscription, $payment]), [
            'notes' => 'تغيير', 'amendment_reason' => 'تصحيح',
        ])->assertForbidden();
        $this->put(route('subscriptions.transactions.update', [$subscription, $payment]), [
            'amount' => '1', 'currency' => 'ILS', 'payment_method' => 'cash', 'correction_reason' => 'wrong_amount',
        ])->assertForbidden();
        $this->delete(route('subscriptions.transactions.destroy', [$subscription, $payment]), [
            'correction_reason' => 'duplicate',
        ])->assertForbidden();
        $this->delete(route('subscriptions.transactions.force-destroy', [$subscription, $payment]), [
            'correction_notes' => 'تصحيح',
        ])->assertForbidden();
        $this->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'delete', 'correction_notes' => 'تصحيح',
        ])->assertSessionHasErrors('action');

        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertDatabaseCount('subscription_transactions', 1);
    }

    #[TestWith(['metadata'])]
    #[TestWith(['amount'])]
    #[TestWith(['identity'])]
    #[TestWith(['cancellation'])]
    #[TestWith(['quiet-update'])]
    #[TestWith(['delete'])]
    #[TestWith(['quiet-delete'])]
    public function test_stale_models_cannot_write_or_delete_a_closed_payment_without_workflow_settings(string $operation): void
    {
        [$branch, $head, , , $payment, $box] = $this->payment('bank_transfer');
        $payment->load('boxEntry.box', 'closingLine.closing');
        app(BranchClosingWorkflow::class)->receive($box, $head);
        ClosingSetting::query()->where('branch_id', $branch->id)->delete();
        app(BranchSettings::class)->forget(ClosingSetting::class);
        $before = $payment->fresh()->getAttributes();

        $this->assertRefused(fn () => match ($operation) {
            'metadata' => $payment->update(['notes' => 'تغيير', 'sender_name' => 'تغيير']),
            'amount' => $payment->update(['amount' => '-1', 'currency_amount' => '1']),
            'identity' => $payment->update(['type' => 'invoice', 'branch_id' => Branch::factory()->create()->id, 'cash_box' => '23']),
            'cancellation' => $payment->update(['status' => 'linked_cancellation', 'cancelled_at' => now(), 'cancelled_by' => $head->id, 'cancellation_reason' => 'payment_refunded', 'cancellation_notes' => 'تغيير', 'active_reference' => null]),
            'quiet-update' => $payment->updateQuietly(['balance_after' => '999']),
            'delete' => $payment->delete(),
            'quiet-delete' => $payment->deleteQuietly(),
        });

        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertSame('100.00', $box->fresh()->totals()['ILS']);
        $this->assertDatabaseCount('cash_box_payments', 1);
    }

    #[TestWith(['open'])]
    #[TestWith(['pending'])]
    public function test_open_membership_has_no_final_box_number_and_closure_freezes_it(string $status): void
    {
        [, $head, $collector, $subscription, $payment, $box] = $this->payment();
        $box->update(['status' => $status, 'closed_at' => $status === 'pending' ? now() : null]);

        $this->actingAs($head)->get(route('subscriptions.statement', $subscription))->assertInertia(fn ($page) => $page
            ->where('entries.0.cashBox', null)
            ->where('entries.0.available_actions', ['edit_metadata', 'delete']));
        $this->get(route('subscriptions.payments.receipt', [$subscription, $payment]))->assertInertia(fn ($page) => $page
            ->where('receipt.cashBox', null));
        $payment->amend($head, ['notes' => 'قبل الإغلاق'], 'تصحيح');
        $payment->update(['amount' => '-75.00', 'currency_amount' => '75.00']);
        $this->assertSame('75.00', $box->fresh()->totals()['ILS']);

        app(BranchClosingWorkflow::class)->receive($box, $head);
        $before = $payment->fresh()->getAttributes();
        $next = SubscriptionTransaction::recordPayment($subscription, $collector, ['amount' => '40', 'currency' => 'ILS', 'payment_method' => 'cash']);
        $this->assertNotSame($box->id, $next->boxEntry->cash_box_id);
        $this->assertSame('open', $next->boxEntry->box->status);
        $next->amend($head, ['notes' => 'دفعة جديدة'], 'تصحيح');

        $this->get(route('subscriptions.statement', $subscription))->assertInertia(fn ($page) => $page
            ->where('entries.0.cashBox', $box->number)
            ->where('entries.0.available_actions', [])
            ->where('entries.1.cashBox', null));
        $this->get(route('subscriptions.payments.receipt', [$subscription, $payment]))->assertInertia(fn ($page) => $page
            ->where('receipt.cashBox', $box->number));
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertSame('75.00', $box->fresh()->totals()['ILS']);
        $this->assertSame($box->id, $payment->boxEntry()->firstOrFail()->cash_box_id);
    }

    #[TestWith(['open'])]
    #[TestWith(['pending'])]
    public function test_last_payments_in_editable_boxes_can_still_be_deleted(string $status): void
    {
        [, $head, , $subscription, $payment, $box] = $this->payment();
        $box->update(['status' => $status]);

        $this->actingAs($head)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'delete', 'correction_notes' => 'دفعة مكررة',
        ])->assertSessionHasNoErrors();

        $this->assertModelMissing($payment);
        $this->assertDatabaseCount('cash_box_payments', 0);
    }

    #[TestWith(['open'])]
    #[TestWith(['pending'])]
    public function test_non_last_payments_in_editable_boxes_can_still_be_refunded(string $status): void
    {
        [, $head, , $subscription, $payment, $box] = $this->payment();
        $box->update(['status' => $status]);
        SubscriptionTransaction::recordCharge($subscription, $head, ChargeType::Penalty, '25', null);

        $this->actingAs($head)->post(route('subscriptions.transactions.actions.store', [$subscription, $payment]), [
            'action' => 'refund',
        ])->assertSessionHasNoErrors();

        $this->assertSame('linked_cancellation', $payment->fresh()->status);
        $this->assertDatabaseHas('subscription_transactions', ['type' => 'refund', 'reference_transaction_id' => $payment->id, 'amount' => '100.00']);
    }

    public function test_deleting_an_earlier_cancellation_tree_preserves_a_later_closed_payments_attributes(): void
    {
        [, $head, $collector, $subscription] = $this->payment();
        $charge = SubscriptionTransaction::recordCharge($subscription, $head, ChargeType::Penalty, '50', null);
        $payment = SubscriptionTransaction::recordPayment($subscription, $collector, ['amount' => '100', 'currency' => 'ILS', 'payment_method' => 'cash']);
        $charge->applyAction($head, TransactionAction::Cancel, []);
        app(BranchClosingWorkflow::class)->receive($payment->boxEntry->box, $head);
        $before = $payment->fresh()->getAttributes();

        $charge->applyAction($head, TransactionAction::DeleteTree, ['correction_notes' => 'تصحيح']);

        $this->assertModelMissing($charge);
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertSame(-200.0, (float) $subscription->transactions()->sum('amount'));
    }

    public function test_a_legacy_refund_cannot_be_deleted_to_reinstate_a_closed_original(): void
    {
        [, $head, , $subscription, $payment, $box] = $this->payment();
        SubscriptionTransaction::recordCharge($subscription, $head, ChargeType::Penalty, '25', null);
        $refund = $payment->applyAction($head, TransactionAction::Refund, []);
        $refund->load('referenceTransaction.closingLine.closing', 'reverses', 'closingLine.closing');
        app(BranchClosingWorkflow::class)->receive($box, $head);
        $before = $payment->fresh()->getAttributes();

        $this->assertSame([], $refund->availableActions($head));
        $this->assertRefused(fn () => $refund->applyAction($head, TransactionAction::DeleteReversal, ['correction_notes' => 'تصحيح']));
        $this->assertRefused(fn () => $refund->erase($head, 'تصحيح'));
        $this->assertSame([], $payment->availableActions($head));
        $this->assertRefused(fn () => $payment->applyAction($head, TransactionAction::DeleteTree, ['correction_notes' => 'تصحيح']));

        $this->assertModelExists($refund);
        $this->assertSame($before, $payment->fresh()->getAttributes());
    }

    public function test_a_stale_open_box_cannot_be_closed_again(): void
    {
        [, $head, , , , $box] = $this->payment();
        $stale = $box->fresh();
        app(BranchClosingWorkflow::class)->receive($box, $head);
        $before = $box->fresh()->getAttributes();

        $this->assertRefused(fn () => app(BranchClosingWorkflow::class)->receive($stale, $head));

        $this->assertSame($before, $box->fresh()->getAttributes());
    }

    public function test_closed_rejected_clearings_cannot_be_cancelled_or_reviewed(): void
    {
        [$branch, $head, $collector, $subscription] = $this->payment();
        $clearing = SubscriptionTransaction::recordClearing($subscription, $collector, '20', 'خدمة');
        $entry = $clearing->boxEntry;
        app(BranchClosingWorkflow::class)->reviewEntry($entry, $head, 'rejected');
        app(BranchClosingWorkflow::class)->receive($entry->box, $head);
        $before = $clearing->fresh()->getAttributes();
        $entryBefore = $entry->fresh()->getAttributes();

        $this->assertSame([], $clearing->availableActions($head));
        $this->assertRefused(fn () => $clearing->cancel($head, CorrectionReason::Other, null));
        $this->actingAs($head)->post(route('payments.cash-boxes.actions', ['action' => 'electronic', 'record' => $entry->id]), [
            'branch_id' => $branch->id, 'status' => 'confirmed',
        ])->assertSessionHasErrors('action');

        $this->assertSame($before, $clearing->fresh()->getAttributes());
        $this->assertSame($entryBefore, $entry->fresh()->getAttributes());
    }

    private function assertRefused(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('A closed-box mutation should be refused.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }
}
