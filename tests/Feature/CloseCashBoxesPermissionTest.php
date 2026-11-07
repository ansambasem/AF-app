<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashBoxPayment;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BranchClosingWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CloseCashBoxesPermissionTest extends TestCase
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

    private function cashBox(Branch $branch, User $collector): CashBox
    {
        $payment = SubscriptionTransaction::recordPayment(Subscription::factory()->for($branch)->create(), $collector, ['amount' => '100', 'currency' => 'ILS', 'payment_method' => 'cash']);

        return CashBoxPayment::query()->where('subscription_transaction_id', $payment->id)->sole()->box;
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

    public function test_user_given_the_permission_confirms_another_collectors_box_closed(): void
    {
        [$branch, $head, $collector] = $this->start();
        $closer = User::factory()->accountant()->for($branch)->withPermissions([PermissionKey::CloseCashBoxes])->create();
        $box = $this->cashBox($branch, $collector);

        $this->actingAs($closer)->get(route('payments.cash-boxes'))->assertInertia(fn (AssertableInertia $page) => $page->component('Payments/BranchCashBoxes'));
        $this->action($closer, $branch, 'receive', $box->id)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cash_boxes', ['id' => $box->id, 'status' => 'received', 'close_reason' => 'manual', 'received_by' => $closer->id, 'confirmed_at' => now()]);
    }

    public function test_permission_does_not_let_a_collector_close_their_own_box(): void
    {
        [$branch, $head, $collector] = $this->start();
        $collector->permissions()->syncWithoutDetaching(Permission::idsFor([PermissionKey::CloseCashBoxes]));
        $own = $this->cashBox($branch, $collector);
        $other = $this->cashBox($branch, User::factory()->collector()->for($branch)->create());

        $this->action($collector, $branch, 'receive', $own->id)->assertSessionHasErrors('action');
        $this->action($collector, $branch, 'receive', $other->id)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cash_boxes', ['id' => $own->id, 'status' => 'open', 'head_counts' => null]);
        $this->assertDatabaseHas('cash_boxes', ['id' => $other->id, 'status' => 'received']);
    }

    public function test_without_the_permission_staff_cannot_close_boxes(): void
    {
        [$branch, $head, $collector] = $this->start();
        $staff = User::factory()->accountant()->for($branch)->create();
        $box = $this->cashBox($branch, $collector);

        $this->actingAs($staff)->get(route('payments.cash-boxes'))->assertInertia(fn (AssertableInertia $page) => $page->component('Payments/CashBoxes'));
        $this->action($staff, $branch, 'receive', $box->id)->assertForbidden();

        $this->assertDatabaseHas('cash_boxes', ['id' => $box->id, 'status' => 'open']);
    }

    public function test_closer_finds_staff_boxes_to_close_on_the_financial_boxes_page(): void
    {
        [$branch, $head, $collector] = $this->start();
        $closer = User::factory()->accountant()->for($branch)->withPermissions([PermissionKey::CloseCashBoxes])->create();
        $closer->permissions()->detach(Permission::idsFor([PermissionKey::RecordCollections, PermissionKey::AddCharges, PermissionKey::AddDiscounts, PermissionKey::AddClearings]));
        $box = $this->cashBox($branch, $collector);
        $this->assertTrue($closer->refresh()->cannot('viewOwnCashBoxes', Subscription::class));
        $transaction = $box->payments()->sole()->transaction;

        $this->actingAs($closer)->get(route('payments.cash-boxes', ['place' => 'open']))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Payments/BranchCashBoxes')
            ->where('branch.id', $branch->id)
            ->where('started', true)
            ->where('boxes.0.id', $box->id)
            ->where('boxes.0.canCount', true)
            ->where('boxes.0.payments.0.receipt_url', route('subscriptions.payments.receipt', [$transaction->subscription_id, $transaction->id]))
            ->where('boxes.0.payments.0.statement_url', route('subscriptions.statement', [$transaction->subscription_id, 'transaction' => $transaction->id]))
            ->where('access.receive', true)
            ->missing('access.send'));
    }

    public function test_head_who_also_collects_finds_their_own_boxes_among_the_branch_boxes(): void
    {
        [$branch, $head, $collector] = $this->start();
        $head->permissions()->syncWithoutDetaching(Permission::idsFor([PermissionKey::RecordCollections]));
        $staffBox = $this->cashBox($branch, $collector);
        $ownBox = $this->cashBox($branch, $head);

        $this->actingAs($head)->get(route('payments.cash-boxes', ['place' => 'open']))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Payments/BranchCashBoxes')
            ->where('boxes', fn ($boxes): bool => collect($boxes)->pluck('id')->sort()->values()->all() === collect([$staffBox->id, $ownBox->id])->sort()->values()->all())
            ->missing('collectionUrls.branchCashBoxes'));
    }

    public function test_staff_boxes_stay_closed_to_those_who_do_not_close_boxes(): void
    {
        [$branch, $head, $collector] = $this->start();
        $staff = User::factory()->accountant()->for($branch)->create();

        $this->actingAs($staff)->get(route('payments.cash-boxes'))->assertInertia(fn (AssertableInertia $page) => $page->component('Payments/CashBoxes'));
        $this->actingAs($collector)->get(route('payments.cash-boxes'))->assertInertia(fn (AssertableInertia $page) => $page->component('Payments/CashBoxes'));
    }

    public function test_branch_admins_get_the_permission_and_may_grant_it(): void
    {
        $this->assertContains(PermissionKey::CloseCashBoxes, UserRole::BranchAdmin->starterPermissions());
        $this->assertFalse(PermissionKey::CloseCashBoxes->isCompanyWide());
        $this->assertFalse(PermissionKey::CloseCashBoxes->isRetired());
        $this->assertSame(PermissionKey::CloseCashBoxes, PermissionKey::resourceGroups()['collections']['actions']['close_boxes']);
        $this->assertNotContains(PermissionKey::SendCashBoxesToAudit, UserRole::BranchAdmin->starterPermissions());
        $this->assertFalse(PermissionKey::SendCashBoxesToAudit->isCompanyWide());
        $this->assertArrayNotHasKey('send_boxes', PermissionKey::resourceGroups()['collections']['actions']);
        $this->assertTrue(PermissionKey::SendCashBoxesToAudit->isRetired());
    }
}
