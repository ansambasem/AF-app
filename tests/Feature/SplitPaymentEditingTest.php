<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Enums\TransactionAction;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\SplitPayment;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BranchClosingWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SplitPaymentEditingTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private SplitPayment $split;

    protected function setUp(): void
    {
        parent::setUp();
        $branch = Branch::factory()->create();
        $this->actor = User::factory()->collector()->for($branch)->withPermissions([PermissionKey::AmendTransactionDetails, PermissionKey::ViewSubscriptions])->create();
        $subscriptions = Subscription::factory()->count(2)->for($branch)->create();
        $this->split = SplitPayment::record($this->actor, [
            'total_amount' => 400, 'bank_name' => 'بنك فلسطين', 'sender_name' => 'أحمد', 'reference_number' => 'OLD-123',
        ], $subscriptions->map(fn (Subscription $subscription, int $index): array => ['subscription' => $subscription, 'amount' => $index === 0 ? 150 : 250])->all());
        $this->actingAs($this->actor);
    }

    public function test_the_editor_updates_all_shared_information_and_audits_it_without_changing_money_or_recreating_payments(): void
    {
        $parts = $this->split->payments;
        $before = $parts->map(fn (SubscriptionTransaction $part): array => array_intersect_key($part->getAttributes(), array_flip(['id', 'amount', 'currency_amount', 'balance_after', 'voucher_number', 'created_at', 'status'])))->all();
        $this->getJson(route('split-payments.edit', $this->split))->assertOk()->assertJsonPath('total_amount', '400.00')->assertJsonCount(2, 'parts');
        $this->patch(route('split-payments.update', $this->split), $this->payload())->assertSessionHasNoErrors();
        $this->assertSame($before, $parts->map(fn (SubscriptionTransaction $part): array => array_intersect_key($part->fresh()->getAttributes(), array_flip(['id', 'amount', 'currency_amount', 'balance_after', 'voucher_number', 'created_at', 'status'])))->all());
        $this->assertSame([-150.0, -250.0], $parts->map(fn (SubscriptionTransaction $part): float => $part->subscription->balance())->all());
        $this->assertSame('400.00', $this->split->standingTotal());
        $this->assertSame('400.00', $parts[0]->boxEntry->box->fresh()->totals()['ILS']);
        $this->assertDatabaseCount('subscription_transactions', 2);
        $this->assertDatabaseCount('transaction_amendments', 2);
        foreach ($parts as $part) {
            $this->assertSame('NEW456', $part->fresh()->active_reference);
            $this->assertSame('محمد', $part->fresh()->sender_name);
            $amendment = $part->amendments()->sole();
            $this->assertSame($this->actor->id, $amendment->user_id);
            $this->assertSame('تصحيح بيانات الحوالة', $amendment->reason);
            $this->assertSame(['OLD-123', 'NEW-456'], $amendment->changes['reference_number']);
            $this->assertSame(['أحمد', 'محمد'], $amendment->changes['sender_name']);
        }
        $this->assertSame('NEW-456', $this->split->fresh()->reference_number);
        $this->assertNull(SubscriptionTransaction::activeReferenceConflict('OLD-123'));
    }

    #[TestWith([0, 'canonical'])]
    #[TestWith([1, 'legacy'])]
    public function test_edits_from_either_subscriber_use_the_shared_transfer(int $index, string $route): void
    {
        $part = $this->split->payments[$index];
        $data = ['bank_name' => 'بنك فلسطين', 'sender_name' => 'محمد', 'reference_number' => 'NEW-456', 'amendment_reason' => 'تصحيح'];
        if ($route === 'canonical') {
            $this->post(route('subscriptions.transactions.actions.store', [$part->subscription, $part]), ['action' => 'edit_metadata', ...$data])->assertSessionHasNoErrors();
        } else {
            $this->patch(route('subscriptions.transactions.amend', [$part->subscription, $part]), $data)->assertSessionHasNoErrors();
        }
        $this->assertSame(['محمد'], $this->split->payments()->pluck('sender_name')->unique()->all());
        $this->assertSame(['NEW456'], $this->split->payments()->pluck('active_reference')->unique()->all());
    }

    #[TestWith(['total_amount', 900])]
    #[TestWith(['amount', 900])]
    #[TestWith(['parts', [['id' => 1, 'amount' => 900]]])]
    #[TestWith(['currency', 'USD'])]
    public function test_amounts_and_allocation_are_protected_even_from_a_super_admin(string $field, mixed $value): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->patch(route('split-payments.update', $this->split), [...$this->payload(), $field => $value])->assertSessionHasErrors($field);
        $this->assertSame('400.00', $this->split->standingTotal());
        $this->assertSame('OLD-123', $this->split->fresh()->reference_number);
        $this->assertDatabaseCount('transaction_amendments', 0);
    }

    public function test_a_normalized_reference_used_in_another_branch_refuses_all_changes(): void
    {
        $other = Subscription::factory()->create();
        SubscriptionTransaction::recordPayment($other, $this->actor, ['amount' => 10, 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'reference_number' => 'new / 456']);
        $this->patch(route('split-payments.update', $this->split), $this->payload())->assertSessionHasErrors('reference_number');
        $this->assertSame(['OLD-123'], $this->split->payments()->pluck('reference_number')->unique()->all());
        $this->assertSame(['أحمد'], $this->split->payments()->pluck('sender_name')->unique()->all());
        $this->assertDatabaseCount('transaction_amendments', 0);
    }

    public function test_a_closed_sibling_blocks_loading_and_saving_the_editor_without_partial_edits(): void
    {
        $parts = $this->split->payments;
        $payload = $this->payload();
        $box = CashBox::factory()->bankTransfer()->create(['branch_id' => $this->actor->branch_id, 'closing_id' => $parts[0]->boxEntry->box->closing_id]);
        $parts[1]->boxEntry->update(['cash_box_id' => $box->id]);
        app(BranchClosingWorkflow::class)->receive($box, User::factory()->superAdmin()->create());
        $this->getJson(route('split-payments.edit', $this->split))->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->patch(route('split-payments.update', $this->split), $payload)->assertSessionHasErrors('action');
        $this->assertSame(['OLD-123'], $this->split->payments()->pluck('reference_number')->unique()->all());
        $this->assertDatabaseCount('transaction_amendments', 0);
    }

    public function test_a_stale_editor_cannot_overwrite_a_recent_edit(): void
    {
        $payload = $this->payload();
        $this->patch(route('split-payments.update', $this->split), $payload)->assertSessionHasNoErrors();
        $this->patch(route('split-payments.update', $this->split), [...$payload, 'sender_name' => 'قديم'])->assertSessionHasErrors('action');
        $this->assertSame(['محمد'], $this->split->payments()->pluck('sender_name')->unique()->all());
        $this->assertDatabaseCount('transaction_amendments', 2);
    }

    public function test_punctuation_alone_cannot_remove_the_reference_from_every_share(): void
    {
        $this->patch(route('split-payments.update', $this->split), [...$this->payload(), 'reference_number' => '--- / .'])
            ->assertSessionHasErrors(['reference_number' => 'أدخل رقمًا مرجعيًا صالحًا.']);
        $this->assertSame(['OLD123'], $this->split->payments()->pluck('active_reference')->unique()->all());
        $this->assertDatabaseCount('transaction_amendments', 0);
    }

    public function test_amendment_permission_and_an_explanation_are_required(): void
    {
        $payload = $this->payload();
        $this->patch(route('split-payments.update', $this->split), [...$payload, 'amendment_reason' => ''])->assertSessionHasErrors('amendment_reason');
        $this->actingAs(User::factory()->collector()->create(['branch_id' => $this->actor->branch_id]));
        $this->getJson(route('split-payments.edit', $this->split))->assertForbidden();
        $this->patch(route('split-payments.update', $this->split), $payload)->assertForbidden();
        $this->assertDatabaseCount('transaction_amendments', 0);
    }

    public function test_hidden_foreign_shares_prevent_loading_or_saving_a_partial_transfer(): void
    {
        $payload = $this->payload();
        SubscriptionTransaction::recordPayment(Subscription::factory()->create(), $this->actor, ['amount' => 10, 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'reference_number' => 'OLD-123', 'split_payment_id' => $this->split->id]);
        $this->getJson(route('split-payments.edit', $this->split))->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->patch(route('split-payments.update', $this->split), $payload)->assertSessionHasErrors('action');
        $this->assertSame(['OLD-123'], $this->split->payments()->pluck('reference_number')->unique()->all());
        $this->assertDatabaseCount('transaction_amendments', 0);
    }

    public function test_refunding_after_an_edit_still_returns_the_whole_transfer(): void
    {
        $this->patch(route('split-payments.update', $this->split), $this->payload())->assertSessionHasNoErrors();
        $this->split->payments[0]->applyAction(User::factory()->superAdmin()->create(), TransactionAction::Refund, ['correction_notes' => 'لم تصل']);
        $this->assertSame('0.00', $this->split->standingTotal());
        $this->assertSame(2, SubscriptionTransaction::where('type', 'refund')->count());
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $revision = $this->getJson(route('split-payments.edit', $this->split))->assertOk()->json('revision');

        return ['bank_name' => 'البنك الإسلامي الفلسطيني', 'sender_bank_name' => 'البنك الإسلامي العربي', 'sender_name' => 'محمد', 'reference_number' => 'NEW-456', 'notes' => 'البيانات الصحيحة', 'amendment_reason' => 'تصحيح بيانات الحوالة', 'revision' => $revision];
    }
}
