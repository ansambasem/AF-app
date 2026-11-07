<?php

namespace Tests\Feature;

use App\Enums\CashBoxType;
use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashBoxPayment;
use App\Models\MobileAccessToken;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Notifications\BranchClosingNotification;
use App\Support\BranchClosingWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CollectorBoxTypesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Branch, User, User} */
    private function start(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(10, 0));
        $branch = Branch::factory()->create();
        $head = User::factory()->branchAdmin()->for($branch)->create();
        $collector = User::factory()->collector()->for($branch)->create();
        app(BranchClosingWorkflow::class)->start($branch->id, $head);

        return [$branch, $head, $collector];
    }

    private function entry(Branch $branch, User $collector, CashBoxType $type, string $amount = '100', ?Subscription $subscription = null): CashBoxPayment
    {
        $subscription ??= Subscription::factory()->for($branch)->create();
        $transaction = $type === CashBoxType::Clearing
            ? SubscriptionTransaction::recordClearing($subscription, $collector, $amount, 'صيانة مولد')
            : SubscriptionTransaction::recordPayment($subscription, $collector, ['amount' => $amount, 'currency' => 'ILS', 'payment_method' => $type->value, 'bank_name' => 'بنك فلسطين', 'reference_number' => 'TRX-'.Str::ulid()]);

        return CashBoxPayment::query()->where('subscription_transaction_id', $transaction->id)->sole();
    }

    /**
     * One of the branch's actions on its boxes.
     *
     * @param  array<string, mixed>  $data
     */
    private function action(User $actor, Branch $branch, string $action, int $record, array $data = []): TestResponse
    {
        return $this->actingAs($actor)->post(route('payments.cash-boxes.actions', ['action' => $action, 'record' => $record]), ['branch_id' => $branch->id, ...$data]);
    }

    /** @return array<string, string> */
    private function counts(string $ils = '100'): array
    {
        return ['ILS' => $ils, 'USD' => '0', 'JOD' => '0'];
    }

    public function test_cash_transfer_and_clearing_go_into_separate_boxes(): void
    {
        [$branch, $head, $collector] = $this->start();
        $cash = $this->entry($branch, $collector, CashBoxType::Cash);
        $transfer = $this->entry($branch, $collector, CashBoxType::BankTransfer);
        $clearing = $this->entry($branch, $collector, CashBoxType::Clearing);

        $this->assertDatabaseCount('cash_boxes', 3);
        foreach ([$cash, $transfer, $clearing] as $entry) {
            $this->assertDatabaseHas('cash_boxes', ['id' => $entry->cash_box_id, 'user_id' => $collector->id, 'type' => $entry->method, 'open_key' => $branch->id.':'.$collector->id.':'.$entry->method, 'status' => 'open']);
        }
        $this->assertSame('pending', $clearing->status);
        $this->assertSame('100.00', $clearing->box->totals()['ILS']);
    }

    public function test_closing_one_box_leaves_the_other_types_open(): void
    {
        [$branch, $head, $collector] = $this->start();
        $cash = $this->entry($branch, $collector, CashBoxType::Cash);
        $transfer = $this->entry($branch, $collector, CashBoxType::BankTransfer);
        $clearing = $this->entry($branch, $collector, CashBoxType::Clearing);

        $this->action($head, $branch, 'receive', $cash->cash_box_id)->assertSessionHasNoErrors();
        $next = $this->entry($branch, $collector, CashBoxType::Cash, '25');

        $this->assertSame('completed', $cash->box->fresh()->stage());
        $this->assertSame('open', $transfer->box->fresh()->status);
        $this->assertSame('open', $clearing->box->fresh()->status);
        $this->assertNotSame($cash->cash_box_id, $next->cash_box_id);
        $this->assertSame(3, CashBox::query()->where('status', 'open')->count());
    }

    public function test_collectors_cannot_close_their_own_boxes(): void
    {
        [$branch, $head, $collector] = $this->start();
        $cash = $this->entry($branch, $collector, CashBoxType::Cash);
        $transfer = $this->entry($branch, $collector, CashBoxType::BankTransfer);

        $this->action($collector, $branch, 'close', $cash->cash_box_id, ['counts' => $this->counts()])->assertForbidden();
        $this->action($collector, $branch, 'collector-count', $transfer->cash_box_id)->assertForbidden();
        $this->action($collector, $branch, 'receive', $cash->cash_box_id)->assertForbidden();
        $this->action($collector, $branch, 'receive', $transfer->cash_box_id)->assertForbidden();

        $this->assertSame(2, CashBox::query()->where('status', 'open')->count());
    }

    public function test_branch_confirms_a_non_cash_box_closed_in_one_step_confirming_its_unmarked_entries(): void
    {
        [$branch, $head, $collector] = $this->start();
        $entry = $this->entry($branch, $collector, CashBoxType::BankTransfer);

        $this->action($head, $branch, 'receive', $entry->cash_box_id)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cash_boxes', ['id' => $entry->cash_box_id, 'status' => 'received', 'close_reason' => 'manual', 'received_by' => $head->id, 'collector_counts' => null, 'head_counts' => null]);
        $this->assertDatabaseHas('cash_box_payments', ['id' => $entry->id, 'status' => 'confirmed', 'confirmed_by' => $head->id]);
    }

    public function test_the_collector_corrects_an_entry_until_the_branch_confirms_its_box_closed(): void
    {
        [$branch, $head] = $this->start();
        $collector = User::factory()->collector()->withPermissions([PermissionKey::AmendTransactionDetails])->for($branch)->create();
        $entry = $this->entry($branch, $collector, CashBoxType::BankTransfer);
        $transaction = $entry->transaction;
        $amend = fn (string $bank): TestResponse => $this->actingAs($collector)->patch(route('subscriptions.transactions.amend', [$transaction->subscription, $transaction]), ['bank_name' => $bank, 'amendment_reason' => 'طلب مدير الفرع']);

        $amend('جوال باي')->assertSessionHasNoErrors();
        $this->assertSame('جوال باي', $transaction->fresh()->bank_name);

        $this->action($head, $branch, 'receive', $entry->cash_box_id)->assertSessionHasNoErrors();

        $this->assertTrue($transaction->fresh()->isInClosedDay());
        $amend('بنك فلسطين')->assertForbidden();
    }

    public function test_a_legacy_pending_cash_box_keeps_its_expected_cash_in_step_with_corrections(): void
    {
        [$branch, $head, $collector] = $this->start();
        $entry = $this->entry($branch, $collector, CashBoxType::Cash);
        $entry->box->update(['status' => 'pending', 'open_key' => null, 'closed_at' => now(), 'close_reason' => 'departure']);
        $this->assertSame('pending', $entry->box->fresh()->status);

        $entry->transaction->update(['amount' => '-150', 'currency_amount' => '150']);

        $this->assertSame('150.00', $entry->fresh()->amount);
        $this->assertSame('150.00', $entry->box->fresh()->expected_counts['ILS']);
        $this->action($head, $branch, 'receive', $entry->cash_box_id)->assertSessionHasNoErrors();
        $this->assertSame('150.00', $entry->box->fresh()->head_counts['ILS']);
    }

    public function test_a_cash_box_closes_as_a_transfer_box_does_without_counting_the_cash(): void
    {
        [$branch, $head, $collector] = $this->start();
        $cash = $this->entry($branch, $collector, CashBoxType::Cash);
        $transfer = $this->entry($branch, $collector, CashBoxType::BankTransfer);

        $this->action($head, $branch, 'receive', $cash->cash_box_id)->assertSessionHasNoErrors();
        $this->action($head, $branch, 'receive', $transfer->cash_box_id)->assertSessionHasNoErrors();

        $this->assertSame(['received', 'completed'], [$cash->box->fresh()->status, $cash->box->fresh()->stage()]);
        $this->assertSame(['received', 'completed'], [$transfer->box->fresh()->status, $transfer->box->fresh()->stage()]);
    }

    public function test_a_payment_in_dollars_sits_in_the_box_at_its_value_in_shekels(): void
    {
        [$branch, , $collector] = $this->start();
        $payment = SubscriptionTransaction::recordPayment(Subscription::factory()->for($branch)->create(), $collector, ['amount' => '10', 'currency' => 'USD', 'exchange_rate' => '3.6', 'payment_method' => 'cash']);
        $entry = CashBoxPayment::query()->where('subscription_transaction_id', $payment->id)->sole();

        $this->assertSame(['ILS', '36.00'], [$entry->currency, $entry->amount]);
        $this->assertSame(['ILS' => '36.00', 'USD' => '0.00', 'JOD' => '0.00'], $entry->box->totals());
        $this->assertSame(['10.00', false, '3.6'], [$payment->ledgerDetails()['paidAmount'], $payment->ledgerDetails()['isShekel'], $payment->ledgerDetails()['exchangeRate']]);
    }

    public function test_rejected_entries_stay_rejected_when_the_branch_confirms_the_box(): void
    {
        [$branch, $head, $collector] = $this->start();
        $accepted = $this->entry($branch, $collector, CashBoxType::Clearing);
        $rejected = $this->entry($branch, $collector, CashBoxType::Clearing, '25');

        $this->action($head, $branch, 'electronic', $rejected->id, ['status' => 'rejected'])->assertSessionHasNoErrors();
        $this->action($head, $branch, 'receive', $accepted->cash_box_id)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cash_boxes', ['id' => $accepted->cash_box_id, 'status' => 'received', 'confirmed_at' => now(), 'received_by' => $head->id, 'head_counts' => null]);
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertSame('confirmed', $accepted->fresh()->status);
    }

    public function test_rejected_clearing_notifies_branch_admin_not_collector(): void
    {
        [$branch, $head, $collector] = $this->start();
        $entry = $this->entry($branch, $collector, CashBoxType::Clearing);
        Notification::fake([BranchClosingNotification::class]);

        $this->action($head, $branch, 'electronic', $entry->id, ['status' => 'rejected'])->assertSessionHasNoErrors();

        Notification::assertSentTo($head, BranchClosingNotification::class, fn (BranchClosingNotification $notification): bool => $notification->details['entry_id'] === $entry->id);
        Notification::assertNotSentTo($collector, BranchClosingNotification::class);
        $this->assertDatabaseHas('cash_box_payments', ['id' => $entry->id, 'status' => 'rejected']);
    }

    public function test_branch_admin_can_review_and_receive_entries_in_own_box(): void
    {
        [$branch, $head] = $this->start();
        $entry = $this->entry($branch, $head, CashBoxType::Clearing);

        $this->action($head, $branch, 'electronic', $entry->id, ['status' => 'confirmed'])->assertSessionHasNoErrors();
        $this->action($head, $branch, 'receive', $entry->cash_box_id)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cash_box_payments', ['id' => $entry->id, 'status' => 'confirmed', 'confirmed_by' => $head->id]);
    }

    public function test_editing_cash_payment_to_transfer_moves_it_to_transfer_box(): void
    {
        [$branch, $head, $collector] = $this->start();
        $entry = $this->entry($branch, $collector, CashBoxType::Cash);
        $occurred = $entry->occurred_at;

        $entry->transaction->update(['payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'reference_number' => 'EDIT-1']);

        $moved = CashBoxPayment::query()->sole();
        $this->assertSame(CashBoxType::BankTransfer, $moved->box->type);
        $this->assertNotSame($entry->cash_box_id, $moved->cash_box_id);
        $this->assertTrue($occurred->equalTo($moved->occurred_at));
        $this->assertSame('0.00', $entry->box->fresh()->totals()['ILS']);
        $moved->transaction->update(['payment_method' => 'cash']);
        $this->assertSame($entry->cash_box_id, CashBoxPayment::query()->sole()->cash_box_id);
    }

    public function test_collector_sees_own_box_transactions_only(): void
    {
        [$branch, $head, $collector] = $this->start();
        $entry = $this->entry($branch, $collector, CashBoxType::BankTransfer);
        $other = $this->entry($branch, $head, CashBoxType::Cash);

        $this->actingAs($collector)->get(route('payments.cash-boxes.show', $entry->cash_box_id))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Payments/CashBoxShow')->where('box.type', 'bank_transfer')->where('box.stage', 'open')
            ->where('box.totals.ILS', '100.00')->has('entries.data', 1)->where('entries.data.0.referenceNumber', $entry->transaction->reference_number)
            ->where('entries.data.0.accountNumber', $entry->transaction->subscription->account_number));
        $this->get(route('payments.cash-boxes.show', $other->cash_box_id))->assertNotFound();
        $this->actingAs(User::factory()->dataEntry()->for($branch)->create())->get(route('payments.cash-boxes.show', $entry->cash_box_id))->assertForbidden();
        $this->actingAs(User::factory()->collector()->create())->get(route('payments.cash-boxes.show', $entry->cash_box_id))->assertNotFound();
    }

    public function test_user_with_record_collections_permission_gets_boxes_whatever_role(): void
    {
        [$branch] = $this->start();
        $collector = User::factory()->dataEntry()->for($branch)->withPermissions([PermissionKey::RecordCollections])->create();
        MobileAccessToken::issue($collector);
        $entry = $this->entry($branch, $collector, CashBoxType::Cash);

        $this->actingAs($collector)->get(route('payments.summary'))->assertInertia(fn (AssertableInertia $page) => $page->where('current.cash.id', $entry->cash_box_id)->where('current.bank_transfer', null)->where('current.clearing', null));
        $this->action($collector, $branch, 'close', $entry->cash_box_id, ['counts' => $this->counts()])->assertForbidden();
        $this->assertDatabaseHas('cash_boxes', ['id' => $entry->cash_box_id, 'status' => 'open']);
    }

    public function test_review_status_must_match_entry_type(): void
    {
        [$branch, $head, $collector] = $this->start();
        $clearing = $this->entry($branch, $collector, CashBoxType::Clearing);
        $transfer = $this->entry($branch, $collector, CashBoxType::BankTransfer);
        $this->action($head, $branch, 'direct', $transfer->id)->assertForbidden();
        $this->action($head, $branch, 'electronic', $clearing->id, ['status' => 'missing'])->assertSessionHasErrors('action');
        $this->action($head, $branch, 'electronic', $transfer->id, ['status' => 'rejected'])->assertSessionHasErrors('action');

        $this->assertDatabaseHas('cash_box_payments', ['id' => $clearing->id, 'status' => 'pending']);
        $this->assertDatabaseHas('cash_box_payments', ['id' => $transfer->id, 'status' => 'pending']);
    }

    public function test_mobile_api_returns_box_transactions_but_cannot_close_boxes(): void
    {
        [$branch, $head, $collector] = $this->start();
        $entry = $this->entry($branch, $collector, CashBoxType::Clearing);
        $this->withToken(MobileAccessToken::issue($collector))->getJson(route('mobile.cash-boxes.index'))->assertJsonPath('boxes.0.type', 'clearing')->assertJsonPath('boxes.0.stage', 'open')->assertJsonPath('boxes.0.totals.ILS', '100.00')->assertJsonPath('boxes.0.payments.0.transaction.notes', 'صيانة مولد');

        foreach (['close', 'collector-count'] as $action) {
            $this->postJson(route('mobile.cash-boxes.actions', ['action' => $action, 'record' => $entry->cash_box_id]), ['branch_id' => $branch->id, 'reviewed' => true])->assertForbidden();
        }
        $this->assertDatabaseHas('cash_boxes', ['id' => $entry->cash_box_id, 'status' => 'open']);
    }

    public function test_cancelled_entries_stay_visible_but_do_not_count_or_block_receipt(): void
    {
        [$branch, $head, $collector] = $this->start();
        $cancelled = $this->entry($branch, $collector, CashBoxType::Clearing);
        $active = $this->entry($branch, $collector, CashBoxType::Clearing, '25');
        $cancelled->transaction->update(['status' => SubscriptionTransaction::STATUS_CANCELLED]);
        $this->action($head, $branch, 'electronic', $active->id, ['status' => 'confirmed'])->assertSessionHasNoErrors();

        $this->action($head, $branch, 'receive', $active->cash_box_id)->assertSessionHasNoErrors();

        $this->actingAs($collector)->get(route('payments.cash-boxes.show', $active->cash_box_id))->assertInertia(fn (AssertableInertia $page) => $page->where('box.totals.ILS', '25.00')->has('entries.data', 2)->where('entries.data.1.transactionStatus', SubscriptionTransaction::STATUS_CANCELLED));
        $this->assertSame('completed', $active->box->fresh()->stage());
    }

    public function test_collector_box_table_filters_by_type_and_state(): void
    {
        [$branch, $head, $collector] = $this->start();
        $this->entry($branch, $collector, CashBoxType::Cash);
        $entry = $this->entry($branch, $collector, CashBoxType::Clearing);
        $this->action($head, $branch, 'receive', $entry->cash_box_id)->assertSessionHasNoErrors();

        $this->actingAs($collector)->get(route('payments.cash-boxes', ['type' => 'clearing', 'state' => 'closed']))->assertInertia(fn (AssertableInertia $page) => $page->where('boxes.total', 1)->where('boxes.data.0.id', $entry->cash_box_id)->where('filters.type', 'clearing')->where('filters.state', 'closed'));
    }

    public function test_legacy_waiting_boxes_are_open_on_web_and_mobile_and_can_be_closed_by_type(): void
    {
        [$branch, $head, $collector] = $this->start();
        $entry = $this->entry($branch, $collector, CashBoxType::Cash);
        $entry->box->update(['status' => 'pending', 'open_key' => null, 'closed_at' => now(), 'close_reason' => 'departure']);

        $this->actingAs($collector)->get(route('payments.cash-boxes', ['state' => 'open']))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('boxes.total', 1)->where('boxes.data.0.status', 'open')->where('boxes.data.0.stage', 'open')->where('boxes.data.0.closedAt', null));
        $this->get(route('payments.cash-boxes', ['state' => 'closed']))->assertInertia(fn (AssertableInertia $page) => $page->where('boxes.total', 0));
        $this->withToken(MobileAccessToken::issue($collector))->getJson(route('mobile.cash-boxes.index'))
            ->assertJsonPath('boxes.0.status', 'open')->assertJsonPath('boxes.0.stage', 'open')->assertJsonPath('boxes.0.closed_at', null);
        $this->actingAs($head)->get(route('payments.cash-boxes', ['place' => 'open']))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('boxes.0.status', 'open')->where('boxes.0.stage', 'open')->where('boxes.0.closedAt', null)->where('boxes.0.canCount', true));
        $this->get(route('payments.cash-boxes.entries'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries.total', 1)->where('closableBoxes.0.id', $entry->cash_box_id));

        $this->action($head, $branch, 'receive-type', 0, ['type' => 'cash', 'box_ids' => [$entry->cash_box_id]])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cash_boxes', ['id' => $entry->cash_box_id, 'status' => 'received', 'received_by' => $head->id, 'close_reason' => 'manual', 'confirmed_at' => now()]);
    }

    public function test_collector_actions_require_collection_permission(): void
    {
        [$branch] = $this->start();
        $user = User::factory()->dataEntry()->for($branch)->create();
        $entry = $this->entry($branch, $user, CashBoxType::Cash);

        $this->action($user, $branch, 'close', $entry->cash_box_id, ['counts' => $this->counts()])->assertForbidden();
        $this->withToken(MobileAccessToken::issue($user))->getJson(route('mobile.cash-boxes.index'))->assertForbidden();
        $this->assertDatabaseHas('cash_boxes', ['id' => $entry->cash_box_id, 'status' => 'open', 'collector_submitted_at' => null]);
    }

    public function test_staff_with_only_adjustment_permission_see_their_clearings_but_cannot_close_them(): void
    {
        [$branch] = $this->start();
        $user = User::factory()->dataEntry()->for($branch)->withPermissions([PermissionKey::AddClearings])->create();
        $entry = $this->entry($branch, $user, CashBoxType::Clearing);

        $this->actingAs($user)->get(route('payments.summary'))->assertInertia(fn (AssertableInertia $page) => $page->where('current.clearing.id', $entry->cash_box_id));
        $this->get(route('payments.cash-boxes.show', $entry->cash_box_id))->assertOk();
        $this->action($user, $branch, 'receive', $entry->cash_box_id)->assertForbidden();

        $this->assertDatabaseHas('cash_boxes', ['id' => $entry->cash_box_id, 'status' => 'open']);
    }

    public function test_refresh_attaches_historical_clearings_to_the_open_box_after_their_week_ended(): void
    {
        [$branch, , $collector] = $this->start();
        $closing = app(BranchClosingWorkflow::class)->current($branch->id);
        $occurred = now();
        $this->travelTo($closing->coverage_until);
        $transaction = SubscriptionTransaction::withoutEvents(fn (): SubscriptionTransaction => SubscriptionTransaction::factory()->for(Subscription::factory()->for($branch))->create(['branch_id' => $branch->id, 'recorded_by' => $collector->id, 'type' => 'clearing', 'status' => SubscriptionTransaction::STATUS_ACTIVE, 'source_key' => 'clearing:'.Str::ulid(), 'amount' => '-20', 'notes' => 'صيانة قديمة', 'created_at' => $occurred]));

        $this->actingAs($collector)->get(route('payments.summary'))->assertInertia(fn (AssertableInertia $page) => $page->where('current.clearing.totals.ILS', '20.00'));

        $entry = CashBoxPayment::query()->where('subscription_transaction_id', $transaction->id)->sole();
        $this->assertSame('open', $entry->box->status);
    }

    public function test_box_transactions_are_paginated_with_full_box_totals(): void
    {
        [$branch, $head, $collector] = $this->start();
        $first = $this->entry($branch, $collector, CashBoxType::Clearing, '1');
        $subscription = $first->transaction->subscription;
        for ($index = 0; $index < 25; $index++) {
            $this->entry($branch, $collector, CashBoxType::Clearing, '1', $subscription);
        }

        $this->actingAs($collector)->get(route('payments.cash-boxes.show', ['cashBox' => $first->cash_box_id, 'page' => 2]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('box.totals.ILS', '26.00')->where('entries.total', 26)->has('entries.data', 1)->where('entries.data.0.id', $first->id));
    }

    public function test_branch_admin_can_cancel_a_rejected_clearing_while_its_box_is_open(): void
    {
        [$branch, $head, $collector] = $this->start();
        $entry = $this->entry($branch, $collector, CashBoxType::Clearing);
        $this->action($head, $branch, 'electronic', $entry->id, ['status' => 'rejected'])->assertSessionHasNoErrors();

        $this->actingAs($head)->post(route('subscriptions.transactions.actions.store', [$entry->transaction->subscription, $entry->transaction]), ['action' => 'cancel', 'correction_reason' => 'other', 'correction_notes' => 'المقاصة غير مطابقة'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('subscription_transactions', ['id' => $entry->subscription_transaction_id, 'status' => SubscriptionTransaction::STATUS_CANCELLED]);
        $this->assertDatabaseHas('cash_box_payments', ['id' => $entry->id, 'status' => 'rejected']);
        $this->assertSame('0.00', $entry->box->fresh()->totals()['ILS']);
    }

    public function test_a_rejected_clearing_stays_locked_after_its_box_is_closed_and_sent_to_audit(): void
    {
        [$branch, $head, $collector] = $this->start();
        $entry = $this->entry($branch, $collector, CashBoxType::Clearing);
        $this->action($head, $branch, 'electronic', $entry->id, ['status' => 'rejected'])->assertSessionHasNoErrors();
        $this->action($head, $branch, 'receive', $entry->cash_box_id)->assertSessionHasNoErrors();
        $entry->box->update(['sent_at' => now(), 'sent_by' => $head->id]);

        SubscriptionTransaction::factory()->for($entry->transaction->subscription)->create(['type' => 'penalty', 'source_key' => 'after-clearing:'.$entry->id, 'amount' => '20']);
        $before = $entry->transaction->fresh()->getAttributes();
        $this->actingAs($head)->post(route('subscriptions.transactions.actions.store', [$entry->transaction->subscription, $entry->transaction]), ['action' => 'cancel', 'correction_reason' => 'other', 'correction_notes' => 'تصحيح'])->assertSessionHasErrors('action');

        $this->assertSame($before, $entry->transaction->fresh()->getAttributes());
        $this->assertTrue($entry->transaction->fresh()->isInClosedDay());
        $this->assertSame('100.00', $entry->box->fresh()->totals()['ILS']);
    }

    #[TestWith(['inactive'])]
    #[TestWith(['transferred'])]
    public function test_boxes_stay_open_after_collector_departure_until_the_branch_confirms_closure(string $departure): void
    {
        [$branch, $head, $collector] = $this->start();
        $cash = $this->entry($branch, $collector, CashBoxType::Cash);
        $transfer = $this->entry($branch, $collector, CashBoxType::BankTransfer);
        $collector->update($departure === 'inactive' ? ['is_active' => false] : ['branch_id' => Branch::factory()->create()->id]);
        app(BranchClosingWorkflow::class)->refresh($branch->id);

        $this->assertDatabaseHas('cash_boxes', ['id' => $cash->cash_box_id, 'status' => 'open', 'closed_at' => null, 'close_reason' => null, 'collector_submitted_at' => null]);
        $this->assertDatabaseHas('cash_boxes', ['id' => $transfer->cash_box_id, 'status' => 'open', 'closed_at' => null]);
        $this->action($head, $branch, 'receive', $cash->cash_box_id)->assertSessionHasNoErrors();
        $this->action($head, $branch, 'electronic', $transfer->id, ['status' => 'confirmed'])->assertSessionHasNoErrors();
        $this->action($head, $branch, 'receive', $transfer->cash_box_id)->assertSessionHasNoErrors();

        $this->assertNotNull($cash->box->fresh()->confirmed_at);
        $this->assertSame('received', $transfer->box->fresh()->status);
    }

    public function test_period_end_closure_does_not_wait_for_a_collector_whose_permissions_were_revoked(): void
    {
        [$branch, $head, $collector] = $this->start();
        $clearing = $this->entry($branch, $collector, CashBoxType::Clearing);
        $cash = $this->entry($branch, $collector, CashBoxType::Cash);
        $collector->permissions()->detach();
        $this->travelTo($cash->box->closing->coverage_until);
        $this->artisan('closings:process')->assertSuccessful();

        $this->action($collector, $branch, 'collector-count', $cash->cash_box_id, ['counts' => $this->counts()])->assertForbidden();
        $this->action($head, $branch, 'receive', $cash->cash_box_id)->assertSessionHasErrors('action');
        $this->action($head, $branch, 'electronic', $clearing->id, ['status' => 'confirmed'])->assertSessionHasErrors('action');

        $this->assertSame('received', $cash->box->fresh()->status);
        $this->assertSame(CashBox::CLOSE_REASON_PERIOD_END, $cash->box->fresh()->close_reason);
        $this->assertNull($cash->box->fresh()->confirmed_at);
        $this->assertSame('completed', $clearing->box->fresh()->stage());
    }
}
