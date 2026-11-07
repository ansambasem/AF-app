<?php

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\CorrectionReason;
use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashBoxPayment;
use App\Models\Permission;
use App\Models\SplitPayment;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\TransactionDeletion;
use App\Models\User;
use App\Support\BranchClosingWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SplitPaymentGroupActionsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $actor;

    private SplitPayment $split;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->actor = User::factory()->superAdmin()->create();
        $collector = User::factory()->collector()->for($this->branch)->create();
        $subscriptions = Subscription::factory()->count(3)->for($this->branch)->create();
        foreach ($subscriptions as $subscription) {
            SubscriptionTransaction::recordCharge($subscription, $collector, ChargeType::Penalty, '500', null);
        }
        $this->split = SplitPayment::record($collector, [
            'total_amount' => 600, 'bank_name' => 'بنك فلسطين', 'sender_name' => 'أحمد', 'reference_number' => 'SPLIT-123',
        ], $subscriptions->map(fn (Subscription $subscription, int $index): array => ['subscription' => $subscription, 'amount' => ($index + 1) * 100])->all());
    }

    public function test_refunding_any_share_returns_every_share_and_releases_the_open_box_once(): void
    {
        $parts = $this->split->payments;
        SubscriptionTransaction::recordCharge($parts[0]->subscription, $this->actor, ChargeType::Penalty, '25', null);
        $box = $parts[0]->boxEntry->box;

        $this->actOn($parts[1], 'refund')->assertSessionHasNoErrors();

        $this->assertSame('0.00', $this->split->standingTotal());
        $this->assertSame([525.0, 500.0, 500.0], $parts->map(fn (SubscriptionTransaction $part): float => $part->subscription->balance())->all());
        $this->assertSame(0, CashBoxPayment::visibleInBox()->count());
        $this->assertSame('0.00', $box->fresh()->totals()['ILS']);
        foreach ($parts as $part) {
            $this->assertSame('linked_cancellation', $part->fresh()->status);
            $this->assertSame('الحوالة لم تصل', $part->fresh()->cancellation_notes);
            $this->assertDatabaseHas('subscription_transactions', ['type' => 'refund', 'reverses_id' => $part->id, 'amount' => abs((float) $part->amount)]);
        }
        $this->actOn($parts[1], 'refund')->assertSessionHasErrors('action');
        $this->assertSame(3, SubscriptionTransaction::where('type', 'refund')->count());
        $refund = SubscriptionTransaction::where('reverses_id', $parts[1]->id)->sole();
        $this->get(route('subscriptions.statement', $parts[1]->subscription))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries', fn ($entries): bool => collect($entries)->firstWhere('id', $refund->id)['splitPayment']['id'] === $this->split->id));
    }

    #[TestWith(['delete'])]
    #[TestWith(['legacy-delete'])]
    public function test_permanent_deletion_removes_all_shares_and_retains_an_audit_record_for_each_subscriber(string $action): void
    {
        $parts = $this->split->payments;

        $this->actOn($parts[1], $action)->assertSessionHasNoErrors();

        foreach ($parts as $part) {
            $this->assertModelMissing($part);
            $this->assertSame(500.0, $part->subscription->balance());
        }
        $this->assertSame(0, CashBoxPayment::visibleInBox()->count());
        $this->assertSame($parts->pluck('subscription_id')->sort()->values()->all(), TransactionDeletion::pluck('subscription_id')->sort()->values()->all());
        $this->assertSame(['الحوالة لم تصل'], TransactionDeletion::pluck('reason')->unique()->values()->all());
    }

    public function test_legacy_cancellation_returns_all_shares_with_a_linked_reversal_and_reason(): void
    {
        $parts = $this->split->payments;

        $this->actOn($parts[2], 'legacy-cancel')->assertSessionHasNoErrors();

        foreach ($parts as $part) {
            $this->assertNotNull($part->fresh()->cancelled_at);
            $this->assertSame('الحوالة لم تصل', $part->fresh()->cancellation_notes);
            $this->assertSame(500.0, $part->subscription->balance());
        }
        $this->assertSame(3, SubscriptionTransaction::where('type', SubscriptionTransaction::TYPE_REVERSAL)->count());
        $this->assertSame(0, CashBoxPayment::visibleInBox()->count());
    }

    public function test_deleting_a_refund_restores_every_share_and_its_shared_reference(): void
    {
        $parts = $this->split->payments;
        $this->actOn($parts[1], 'refund')->assertSessionHasNoErrors();
        $refund = SubscriptionTransaction::where('reverses_id', $parts[1]->id)->sole();

        $this->actOn($refund, 'delete_reversal')->assertSessionHasNoErrors();

        $this->assertSame('600.00', $this->split->standingTotal());
        $this->assertSame([400.0, 300.0, 200.0], $parts->map(fn (SubscriptionTransaction $part): float => $part->subscription->balance())->all());
        $this->assertSame(0, SubscriptionTransaction::where('type', 'refund')->count());
        $this->assertDatabaseCount('cash_box_payments', 3);
        foreach ($parts as $part) {
            $this->assertSame('SPLIT123', $part->fresh()->active_reference);
            $this->assertFalse($part->fresh()->isCancelled());
        }
    }

    public function test_deleting_the_refunded_tree_removes_all_originals_and_refunds_without_changing_balances(): void
    {
        $part = $this->split->payments[0];
        $this->actOn($part, 'refund')->assertSessionHasNoErrors();

        $this->actOn($part, 'delete_tree')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('subscription_transactions', 3);
        $this->assertSame('0.00', $this->split->standingTotal());
        $this->assertSame(500.0, $part->subscription->balance());
    }

    #[TestWith(['refund'])]
    #[TestWith(['delete'])]
    #[TestWith(['legacy-cancel'])]
    public function test_a_closed_sibling_blocks_the_whole_operation_even_when_the_selected_share_is_open(string $action): void
    {
        $parts = $this->split->payments;
        $this->closeBoxOf($parts[2]);
        $before = $parts->map(fn (SubscriptionTransaction $part): array => $part->fresh()->getAttributes())->all();

        $this->actOn($parts[0], $action)->assertSessionHasErrors('action');

        $this->assertSame($before, $parts->map(fn (SubscriptionTransaction $part): array => $part->fresh()->getAttributes())->all());
        $this->assertSame('600.00', $this->split->standingTotal());
        $this->assertDatabaseCount('subscription_transactions', 6);
        $this->assertDatabaseCount('cash_box_payments', 3);
        $this->assertDatabaseCount('transaction_deletions', 0);
    }

    public function test_a_closed_sibling_takes_every_transfer_wide_action_off_an_open_shares_menu(): void
    {
        $this->actor->permissions()->syncWithoutDetaching(Permission::idsFor([PermissionKey::ForceDeleteTransactions]));
        $parts = $this->split->payments;
        $this->assertSame(['edit_metadata', 'delete', 'refund'], $parts[0]->fresh()->availableActions($this->actor));

        $this->closeBoxOf($parts[2]);

        $this->actingAs($this->actor)->get(route('subscriptions.statement', $parts[0]->subscription))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('entries', fn ($entries): bool => $entries->firstWhere('id', $parts[0]->id)['available_actions'] === []));
    }

    public function test_a_closed_sibling_forbids_erasing_the_selected_open_share(): void
    {
        $parts = $this->split->payments;
        $this->closeBoxOf($parts[2]);

        $this->actOn($parts[0], 'legacy-delete')->assertForbidden();

        $this->assertSame('600.00', $this->split->standingTotal());
        $this->assertDatabaseCount('transaction_deletions', 0);
    }

    public function test_a_later_transaction_on_a_sibling_blocks_deletion_of_all_shares(): void
    {
        $parts = $this->split->payments;
        SubscriptionTransaction::recordCharge($parts[2]->subscription, $this->actor, ChargeType::Penalty, '25', null);

        $this->actOn($parts[0], 'delete')->assertSessionHasErrors('action');

        $this->assertSame('600.00', $this->split->standingTotal());
        $this->assertDatabaseCount('cash_box_payments', 3);
        $this->assertDatabaseCount('transaction_deletions', 0);
    }

    public function test_the_statement_offers_deleting_or_erasing_a_share_only_while_every_share_can_be_deleted(): void
    {
        $this->actor->permissions()->syncWithoutDetaching(Permission::idsFor([PermissionKey::ForceDeleteTransactions]));
        $parts = $this->split->payments;
        $entry = fn (): array => collect($this->actingAs($this->actor)->get(route('subscriptions.statement', $parts[0]->subscription))->inertiaProps('entries'))
            ->firstWhere('id', $parts[0]->id);
        $this->assertSame([['edit_metadata', 'delete', 'refund'], true], [$entry()['available_actions'], $entry()['canForceDelete']]);

        SubscriptionTransaction::recordCharge($parts[2]->subscription, $this->actor, ChargeType::Penalty, '25', null);

        $this->assertSame([['edit_metadata', 'refund'], false], [$entry()['available_actions'], $entry()['canForceDelete']]);
        $this->actOn($parts[0], 'legacy-delete')->assertForbidden();
        $this->assertSame('600.00', $this->split->standingTotal());
    }

    #[TestWith(['refund'])]
    #[TestWith(['delete'])]
    public function test_a_collector_without_the_action_permission_cannot_mutate_any_share(string $action): void
    {
        $this->actor = User::factory()->collector()->for($this->branch)->withPermissions([PermissionKey::ViewSubscriptions])->create();

        $this->actOn($this->split->payments[0], $action)->assertSessionHasErrors('action');

        $this->assertSame('600.00', $this->split->standingTotal());
        $this->assertDatabaseCount('subscription_transactions', 6);
    }

    public function test_a_hidden_foreign_branch_share_prevents_partial_refunding(): void
    {
        $foreign = Subscription::factory()->create();
        SubscriptionTransaction::recordPayment($foreign, $this->actor, [
            'amount' => 50, 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين',
            'reference_number' => 'SPLIT-123', 'split_payment_id' => $this->split->id,
        ]);
        $this->actor = User::factory()->collector()->for($this->branch)->withPermissions([PermissionKey::RefundPayments, PermissionKey::ViewSubscriptions])->create();

        $this->actOn($this->split->payments[0], 'refund')->assertSessionHasErrors('action');

        $this->assertSame('650.00', $this->split->standingTotal());
        $this->assertSame(0, SubscriptionTransaction::where('type', 'refund')->count());
    }

    #[TestWith(['refund'])]
    #[TestWith(['delete'])]
    public function test_corrected_shares_are_processed_without_double_refunding_or_reinstating_old_payments(string $action): void
    {
        $original = $this->split->payments[1];
        $replacement = $original->correct($this->actor, CorrectionReason::WrongAmount, 'خطأ', fn (Subscription $subscription): SubscriptionTransaction => SubscriptionTransaction::recordPayment($subscription, $this->actor, [
            'amount' => 150, 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين',
            'reference_number' => 'SPLIT-123', 'split_payment_id' => $this->split->id,
        ]));

        $this->actOn($replacement, $action)->assertSessionHasNoErrors();

        $this->assertSame('0.00', $this->split->standingTotal());
        $this->assertSame(500.0, $original->subscription->balance());
        $this->assertSame(0, CashBoxPayment::visibleInBox()->count());
        if ($action === 'refund') {
            $this->assertSame(3, SubscriptionTransaction::where('type', 'refund')->count());
            $this->assertDatabaseHas('subscription_transactions', ['type' => 'refund', 'reverses_id' => $replacement->id, 'amount' => '150.00']);
            $refund = SubscriptionTransaction::where('reverses_id', $replacement->id)->sole();
            $this->actOn($refund, 'delete_reversal')->assertSessionHasNoErrors();
            $this->assertSame('550.00', $this->split->standingTotal());
            $this->assertTrue($original->fresh()->isCancelled());
        } else {
            $this->assertDatabaseCount('subscription_transactions', 3);
        }
    }

    public function test_an_employee_with_refund_permission_can_return_all_shares_in_their_branch(): void
    {
        $this->actor = User::factory()->collector()->for($this->branch)->withPermissions([PermissionKey::ViewSubscriptions, PermissionKey::RefundPayments])->create();

        $this->actOn($this->split->payments[0], 'refund')->assertSessionHasNoErrors();

        $this->assertSame('0.00', $this->split->standingTotal());
        $this->assertSame(3, SubscriptionTransaction::where('type', 'refund')->count());
    }

    public function test_a_branch_domain_cannot_hide_a_foreign_share_even_from_a_super_admin(): void
    {
        $part = $this->split->payments[0];
        $foreign = Subscription::factory()->create();
        SubscriptionTransaction::recordPayment($foreign, $this->actor, [
            'amount' => 50, 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين',
            'reference_number' => 'SPLIT-123', 'split_payment_id' => $this->split->id,
        ]);
        config(['app.url' => 'https://admin.company.test', 'powercollect.branch_domains_enabled' => true]);
        $this->branch->forceFill(['domain' => 'north.company.test', 'domain_enabled' => true])->save();

        $this->actingAs($this->actor)->post('https://north.company.test/subscriptions/'.$part->subscription_id.'/transactions/'.$part->id.'/actions', [
            'action' => 'refund', 'correction_notes' => 'الحوالة لم تصل',
        ])->assertStatus(302)->assertSessionHasErrors('action');

        $this->assertDatabaseCount('subscription_transactions', 7);
        $this->assertDatabaseMissing('subscription_transactions', ['type' => 'refund']);
        $this->assertDatabaseMissing('subscription_transactions', ['split_payment_id' => $this->split->id, 'status' => 'linked_cancellation']);
    }

    public function test_a_failure_after_the_first_refund_rolls_back_every_share_and_balance(): void
    {
        $parts = $this->split->payments;
        $secondSubscription = $parts[1]->subscription_id;
        SubscriptionTransaction::creating(function (SubscriptionTransaction $line) use ($secondSubscription): void {
            if ($line->type === 'refund' && $line->subscription_id === $secondSubscription) {
                throw new \RuntimeException('Refund recording failed');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->actOn($parts[0], 'refund');
            $this->fail('The second refund should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Refund recording failed', $exception->getMessage());
        }

        $this->assertSame('600.00', $this->split->standingTotal());
        $this->assertSame([400.0, 300.0, 200.0], $parts->map(fn (SubscriptionTransaction $part): float => $part->subscription->balance())->all());
        $this->assertDatabaseMissing('subscription_transactions', ['type' => 'refund']);
        $this->assertSame(3, CashBoxPayment::visibleInBox()->count());
    }

    #[TestWith(['refund'])]
    #[TestWith(['legacy-cancel'])]
    public function test_an_older_partial_refund_is_not_returned_again_when_the_whole_transfer_is_returned(string $action): void
    {
        $part = $this->split->payments[0];
        $part->subscription->transactions()->create([
            'recorded_by' => $this->actor->id, 'type' => 'refund', 'reference_transaction_id' => $part->id,
            'amount' => '50.00', 'currency' => 'ILS', 'source_key' => 'legacy-refund:'.$part->id,
        ]);

        $this->actOn($part, $action)->assertSessionHasNoErrors();

        $this->assertSame('0.00', $this->split->standingTotal());
        $this->assertSame(500.0, $part->subscription->balance());
        $this->assertDatabaseHas('subscription_transactions', ['type' => 'refund', 'reverses_id' => $part->id, 'amount' => '50.00']);
        $this->assertSame('الحوالة لم تصل', $part->fresh()->cancellation_notes);
        $refund = SubscriptionTransaction::where('reverses_id', $part->id)->sole();
        $this->actOn($refund, 'delete_reversal')->assertSessionHasErrors('action');
        $this->assertSame('0.00', $this->split->standingTotal());
        $this->assertSame(500.0, $part->subscription->balance());
    }

    /** Move the share into a box of its own and close that box. */
    private function closeBoxOf(SubscriptionTransaction $part): void
    {
        $closedBox = CashBox::factory()->bankTransfer()->create([
            'branch_id' => $this->branch->id, 'closing_id' => $part->boxEntry->box->closing_id,
            'user_id' => User::factory()->collector()->for($this->branch)->create()->id,
        ]);
        $part->boxEntry->update(['cash_box_id' => $closedBox->id]);
        app(BranchClosingWorkflow::class)->receive($closedBox, $this->actor);
    }

    private function actOn(SubscriptionTransaction $part, string $action): TestResponse
    {
        $this->actingAs($this->actor);
        $data = ['correction_notes' => 'الحوالة لم تصل', 'correction_reason' => CorrectionReason::NotReceived->value];
        if ($action === 'legacy-cancel' || $action === 'legacy-delete') {
            return $this->delete(route($action === 'legacy-cancel' ? 'subscriptions.transactions.destroy' : 'subscriptions.transactions.force-destroy', [$part->subscription, $part]), $data);
        }

        return $this->post(route('subscriptions.transactions.actions.store', [$part->subscription, $part]), ['action' => $action, ...$data]);
    }
}
