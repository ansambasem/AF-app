<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashBoxPayment;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BranchClosingWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CashBoxEntriesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Branch, User, User} */
    private function start(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(10, 0));
        $branch = Branch::factory()->create();
        $head = User::factory()->branchAdmin()->for($branch)->create();
        $collector = User::factory()->collector()->for($branch)->create(['name' => 'سامي']);
        app(BranchClosingWorkflow::class)->start($branch->id, $head);

        return [$branch, $head, $collector];
    }

    private function entry(Branch $branch, User $collector, string $amount = '100', string $method = 'cash'): CashBoxPayment
    {
        $payment = SubscriptionTransaction::recordPayment(Subscription::factory()->for($branch)->create(), $collector, [
            'amount' => $amount, 'currency' => 'ILS', 'payment_method' => $method,
            ...($method === 'bank_transfer' ? ['bank_name' => 'بنك فلسطين', 'sender_bank_name' => 'جوال باي', 'reference_number' => 'TRX-77'] : []),
        ]);

        return CashBoxPayment::query()->where('subscription_transaction_id', $payment->id)->sole();
    }

    public function test_collector_sees_every_entry_as_the_statement_shows_it_with_its_box_and_can_narrow_to_a_type_of_box(): void
    {
        [$branch, , $collector] = $this->start();
        $cash = $this->entry($branch, $collector, '100');
        $transfer = $this->entry($branch, $collector, '250', 'bank_transfer');
        $this->entry($branch, User::factory()->collector()->for($branch)->create(), '999');

        $this->actingAs($collector)->get(route('payments.summary'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Payments/Summary')
            ->where('entries.total', 2)
            ->where('counts', ['all' => 2, 'cash' => 1, 'bank_transfer' => 1, 'clearing' => 0])
            ->where('entries.data.1.id', $cash->id)
            ->where('entries.data.1.box.number', $cash->box->number)
            ->where('entries.data.1.box.url', route('payments.cash-boxes.show', $cash->box))
            ->where('entries.data.1.ledger.voucherNumber', $cash->transaction->displayVoucherNumber())
            ->where('entries.data.1.ledger.description', 'دفعة نقدية')
            ->where('entries.data.1.ledger.recordedByName', 'سامي')
            // A collector who may not open subscribers gets no link to the statement.
            ->where('entries.data.1.statementUrl', null));

        $this->get(route('payments.summary', ['filter' => ['type' => 'bank_transfer']]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries.total', 1)
            ->where('entries.data.0.id', $transfer->id)
            ->where('entries.data.0.box.number', $transfer->box->number)
            ->where('entries.data.0.ledger.referenceNumber', 'TRX-77')
            ->where('entries.data.0.ledger.bankName', 'بنك فلسطين')
            ->where('entries.data.0.ledger.senderBankName', 'جوال باي')
            ->where('entries.data.0.ledger.typeLabel', 'دفعة')
            ->where('filters.filter.type', 'bank_transfer'));
    }

    public function test_closer_sees_staff_entries_with_who_recorded_them_and_a_link_that_opens_the_box(): void
    {
        [$branch, $head, $collector] = $this->start();
        $entry = $this->entry($branch, $collector);

        $this->actingAs($head)->get(route('payments.cash-boxes.entries', ['search' => $entry->box->number]))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Payments/Entries')
            ->where('entries.total', 1)
            ->where('entries.data.0.collector', 'سامي')
            ->where('entries.data.0.ledger.recordedByName', 'سامي')
            ->where('entries.data.0.box.url', route('payments.cash-boxes', ['branch_id' => $branch->id, 'box' => $entry->cash_box_id]))
            ->where('entries.data.0.statementUrl', route('subscriptions.statement', [$entry->transaction->subscription_id, 'transaction' => $entry->subscription_transaction_id])));
    }

    public function test_closer_sees_every_entry_of_the_branch_their_own_among_them_and_narrows_them_to_one_person(): void
    {
        [$branch, $head, $collector] = $this->start();
        $head->permissions()->syncWithoutDetaching(Permission::idsFor([PermissionKey::RecordCollections]));
        $staffEntry = $this->entry($branch, $collector);
        $ownEntry = $this->entry($branch, $head, '40');

        $this->actingAs($head)->get(route('payments.cash-boxes.entries'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries.total', 2)
            ->where('people', [['id' => $head->id, 'name' => $head->name], ['id' => $collector->id, 'name' => 'سامي']]));

        $this->get(route('payments.cash-boxes.entries', ['filter' => ['user' => $head->id]]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries.total', 1)
            ->where('entries.data.0.id', $ownEntry->id)
            ->where('filters.filter.user', (string) $head->id));
        $this->assertNotSame($staffEntry->cash_box_id, $ownEntry->cash_box_id);
    }

    public function test_the_branch_sees_each_box_entry_as_the_statement_shows_it(): void
    {
        [$branch, $head, $collector] = $this->start();
        $entry = $this->entry($branch, $collector, '250', 'bank_transfer');

        $this->actingAs($head)->get(route('payments.cash-boxes', ['place' => 'open']))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('boxes.0.id', $entry->cash_box_id)
            ->where('boxes.0.payments.0.ledger.referenceNumber', 'TRX-77')
            ->where('boxes.0.payments.0.ledger.paymentMethodLabel', __('Bank Transfer'))
            ->where('boxes.0.payments.0.ledger.recordedByName', 'سامي'));
    }

    public function test_the_old_entries_page_sends_each_person_to_where_their_open_entries_now_are(): void
    {
        [$branch, $head, $collector] = $this->start();
        $staff = User::factory()->accountant()->for($branch)->create();
        $asked = ['search' => '12', 'filter' => ['type' => 'cash']];

        $this->actingAs($collector)->get(route('payments.entries', $asked))->assertRedirect(route('payments.summary', $asked));
        $this->actingAs($collector)->get(route('payments.cash-boxes.entries', $asked))->assertRedirect(route('payments.summary', $asked));
        $this->actingAs($staff)->get(route('payments.entries'))->assertRedirect(route('payments.summary'));
        $this->actingAs($head)->get(route('payments.entries', ['branch_id' => $branch->id, ...$asked]))->assertRedirect(route('payments.cash-boxes.entries', ['branch_id' => $branch->id, ...$asked]));
        $this->actingAs(User::factory()->dataEntry()->for($branch)->create())->followingRedirects()->get(route('payments.entries'))->assertForbidden();
    }

    public function test_the_financial_boxes_page_and_its_open_entries_tab_count_each_other(): void
    {
        [$branch, $head, $collector] = $this->start();
        $this->entry($branch, $collector, '100');
        $this->entry($branch, $collector, '250', 'bank_transfer');
        $this->entry($branch, $collector, '40');
        $tabs = [
            ['key' => 'entries', 'label' => 'الحركات', 'count' => 3, 'url' => route('payments.cash-boxes.entries')],
            ['key' => 'open', 'label' => 'الصناديق المفتوحة', 'count' => 2, 'url' => route('payments.cash-boxes', ['place' => 'open'])],
            ['key' => 'closed', 'label' => 'الصناديق المغلقة', 'count' => 0, 'url' => route('payments.cash-boxes', ['place' => 'closed'])],
        ];

        $this->actingAs($head)->get(route('payments.cash-boxes'))->assertInertia(fn (AssertableInertia $page) => $page->component('Payments/BranchCashBoxes')->where('tabs', $tabs));
        $this->get(route('payments.cash-boxes.entries', ['filter' => ['type' => 'bank_transfer']]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tabs', $tabs)
            ->where('entries.total', 1));
    }

    public function test_entries_of_closed_boxes_are_left_to_their_box_for_the_collector_and_the_branch(): void
    {
        [$branch, $head, $collector] = $this->start();
        $closed = $this->entry($branch, $collector, '100');
        $this->actingAs($head)->post(route('payments.cash-boxes.actions', ['action' => 'receive', 'record' => $closed->cash_box_id]), ['branch_id' => $branch->id])->assertSessionHasNoErrors();
        $open = $this->entry($branch, $collector, '40');

        $this->actingAs($collector)->get(route('payments.summary'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries.total', 1)
            ->where('entries.data.0.id', $open->id)
            ->where('counts.all', 1));
        $this->actingAs($head)->get(route('payments.cash-boxes.entries'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries.total', 1)
            ->where('entries.data.0.id', $open->id));
    }

    public function test_closer_closes_the_open_boxes_of_one_type_they_pick_and_leaves_the_rest_open(): void
    {
        [$branch, $head, $collector] = $this->start();
        $other = User::factory()->collector()->for($branch)->create();
        $first = $this->entry($branch, $collector, '100');
        $second = $this->entry($branch, $other, '60');
        $transfer = $this->entry($branch, $collector, '250', 'bank_transfer');

        $this->actingAs($head)->get(route('payments.cash-boxes.entries'))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('closableBoxes', 3)
            ->where('closableBoxes.0.id', $first->cash_box_id)
            ->where('closableBoxes.0.person', 'سامي')
            ->where('closableBoxes.0.count', 1)
            ->where('closableBoxes.0.totals.ILS', '100.00')
            ->where('closableBoxes.0.entries', [['amount' => '100.00', 'bankName' => null, 'service' => null, 'currency' => 'ILS', 'currencyLabel' => __('Shekel'), 'paidAmount' => '100.00']])
            ->where('closableBoxes.2.id', $transfer->cash_box_id)
            ->where('closableBoxes.2.entries.0.bankName', 'بنك فلسطين')
            ->where('closableBoxes.2.entries.0.amount', '250.00'));

        $close = fn (array $boxIds, array $data = []) => $this->post(route('payments.cash-boxes.actions', ['action' => 'receive-type']), ['branch_id' => $branch->id, 'type' => 'cash', 'box_ids' => $boxIds, ...$data]);
        $close([])->assertSessionHasErrors('box_ids');
        $this->assertSame('open', $first->box->fresh()->status);

        $close([$second->cash_box_id])->assertSessionHasNoErrors();

        $this->assertSame('received', $second->box->fresh()->status);
        $this->assertSame($head->id, $second->box->fresh()->received_by);
        $this->assertSame('open', $first->box->fresh()->status);
        $this->assertSame('open', $transfer->box->fresh()->status);
    }

    public function test_nothing_is_closed_when_a_picked_box_is_of_another_type_or_no_longer_open(): void
    {
        [$branch, $head, $collector] = $this->start();
        $cash = $this->entry($branch, $collector, '100');
        $transfer = $this->entry($branch, $collector, '250', 'bank_transfer');
        $close = fn (array $boxIds) => $this->actingAs($head)->post(route('payments.cash-boxes.actions', ['action' => 'receive-type']), ['branch_id' => $branch->id, 'type' => 'cash', 'box_ids' => $boxIds]);

        $close([$cash->cash_box_id, $transfer->cash_box_id])->assertSessionHasErrors('action');
        $this->assertSame('open', $cash->box->fresh()->status);
        $this->assertSame('open', $transfer->box->fresh()->status);

        $close([$cash->cash_box_id])->assertSessionHasNoErrors();
        $close([$cash->cash_box_id])->assertSessionHasErrors('action');
    }

    public function test_only_those_who_may_close_boxes_can_close_them_by_type(): void
    {
        [$branch, , $collector] = $this->start();
        $entry = $this->entry($branch, $collector);
        $staff = User::factory()->accountant()->for($branch)->create();

        $data = ['branch_id' => $branch->id, 'type' => 'cash', 'box_ids' => [$entry->cash_box_id]];
        $this->actingAs($collector)->post(route('payments.cash-boxes.actions', ['action' => 'receive-type']), $data)->assertForbidden();
        $this->actingAs($staff)->post(route('payments.cash-boxes.actions', ['action' => 'receive-type']), $data)->assertForbidden();
        $this->actingAs($collector)->get(route('payments.summary'))->assertInertia(fn (AssertableInertia $page) => $page->missing('closableBoxes'));

        $closer = User::factory()->collector()->for($branch)->create();
        $closer->permissions()->syncWithoutDetaching(Permission::idsFor([PermissionKey::CloseCashBoxes]));
        $this->actingAs($closer)->get(route('payments.cash-boxes.entries'))->assertInertia(fn (AssertableInertia $page) => $page->has('closableBoxes', 1));
        $this->post(route('payments.cash-boxes.actions', ['action' => 'receive-type']), $data)->assertSessionHasNoErrors();

        $this->assertSame('received', CashBox::query()->findOrFail($entry->cash_box_id)->status);
    }
}
