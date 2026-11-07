<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashBoxPayment;
use App\Models\Closing;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BranchClosingWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SendCashBoxesToAuditTest extends TestCase
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

    private function box(Branch $branch, User $collector, string $method = 'cash'): CashBox
    {
        $payment = SubscriptionTransaction::recordPayment(Subscription::factory()->for($branch)->create(), $collector, ['amount' => '100', 'currency' => 'ILS', 'payment_method' => $method, 'bank_name' => 'بنك فلسطين', 'reference_number' => 'TRX-'.$collector->id.'-'.CashBoxPayment::query()->count()]);

        return CashBoxPayment::query()->where('subscription_transaction_id', $payment->id)->sole()->box;
    }

    /** @param array<string, mixed> $data */
    private function action(User $actor, Branch $branch, string $action, ?int $record = null, array $data = []): TestResponse
    {
        return $this->actingAs($actor)->post(route('payments.cash-boxes.actions', ['action' => $action, 'record' => $record]), ['branch_id' => $branch->id, ...$data]);
    }

    private function closed(Branch $branch, User $head, User $collector, string $method = 'cash'): CashBox
    {
        $box = $this->box($branch, $collector, $method);
        $this->action($head, $branch, 'receive', $box->id)->assertSessionHasNoErrors();

        return $box->fresh();
    }

    public function test_sending_closed_boxes_is_no_longer_available_and_sends_no_notification(): void
    {
        [$branch, $head, $collector] = $this->start();
        User::factory()->financialAuditor()->create();
        $cash = $this->closed($branch, $head, $collector);
        $transfer = $this->closed($branch, $head, $collector, 'bank_transfer');
        Notification::fake();

        $this->action($head, $branch, 'send', data: ['box_ids' => [$cash->id, $transfer->id], 'note' => 'ملاحظة'])->assertForbidden();

        $this->assertNull($cash->fresh()->sent_at);
        $this->assertNull($transfer->fresh()->sent_at);
        Notification::assertNothingSent();
    }

    public function test_the_removed_send_action_cannot_mutate_a_mix_of_open_closed_and_historically_sent_boxes(): void
    {
        [$branch, $head, $collector] = $this->start();
        $open = $this->box($branch, $collector);
        $closed = $this->closed($branch, $head, User::factory()->collector()->for($branch)->create());
        $sent = $this->closed($branch, $head, User::factory()->collector()->for($branch)->create(), 'bank_transfer');
        $sent->update(['sent_at' => now()->subHour(), 'sent_by' => $head->id, 'send_note' => 'سجل سابق']);

        $this->action($head, $branch, 'send', data: ['box_ids' => [$closed->id, $open->id, $sent->id]])->assertForbidden();

        $this->assertNull($closed->fresh()->sent_at);
        $this->assertNull($open->fresh()->sent_at);
        $this->assertDatabaseHas('cash_boxes', ['id' => $sent->id, 'sent_at' => now()->subHour(), 'sent_by' => $head->id, 'send_note' => 'سجل سابق']);
    }

    public function test_the_legacy_send_permission_cannot_reenable_sending_and_actions_stay_within_the_branch(): void
    {
        [$branch, $head, $collector] = $this->start();
        $box = $this->closed($branch, $head, $collector);
        $sender = User::factory()->accountant()->for($branch)->withPermissions([PermissionKey::SendCashBoxesToAudit])->create();
        $outsider = User::factory()->branchAdmin()->create();

        $this->action($sender, $branch, 'send', data: ['box_ids' => [$box->id]])->assertForbidden();
        $this->action($outsider, $branch, 'send', data: ['box_ids' => [$box->id]])->assertNotFound();
        $this->action($outsider, $outsider->branch, 'send', data: ['box_ids' => [$box->id]])->assertForbidden();

        $this->assertNull($box->fresh()->sent_at);
    }

    public function test_open_and_closed_boxes_have_two_states_and_historically_sent_boxes_remain_in_closed(): void
    {
        [$branch, $head, $collector] = $this->start();
        $open = $this->box($branch, $collector);
        $closed = $this->closed($branch, $head, User::factory()->collector()->for($branch)->create());
        $sent = $this->closed($branch, $head, User::factory()->collector()->for($branch)->create(), 'bank_transfer');
        $sent->update(['sent_at' => now(), 'sent_by' => $head->id]);

        $this->actingAs($head)->get(route('payments.cash-boxes', ['place' => 'open']))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('place', 'open')->has('boxes', 1)->where('boxes.0.id', $open->id)->where('boxes.0.place', 'open')
            ->has('tabs', 3)->where('tabs.0.key', 'entries')->where('tabs.1.count', 1)->where('tabs.2.count', 2));
        $this->get(route('payments.cash-boxes'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('place', 'closed')->has('boxes', 2)->where('boxes.0.id', $sent->id)
            ->where('boxes.0.closedBy', $head->name)->where('boxes.0.sentBy', $head->name)->missing('boxes.0.canSend'));
        $this->get(route('payments.cash-boxes', ['place' => 'sent']))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('place', 'closed')->has('boxes', 2)->where('boxes.0.place', 'closed'));
    }

    public function test_boxes_of_weeks_sent_before_count_as_sent_by_whoever_sent_the_week_and_branch_admins_may_send(): void
    {
        $this->freezeTime();
        $migration = require database_path('migrations/2026_10_17_120000_send_cash_boxes_to_audit_one_by_one.php');
        $migration->down();
        $branch = Branch::factory()->create();
        $head = User::factory()->branchAdmin()->for($branch)->create();
        $sentWeek = Closing::factory()->for($branch)->create(['workflow_settings' => ['frequency' => 'weekly'], 'submitted_at' => now()->subDay(), 'branch_approved_by' => $head->id]);
        $openWeek = Closing::factory()->for($branch)->create(['workflow_settings' => ['frequency' => 'weekly']]);
        $sentBox = CashBox::factory()->create(['branch_id' => $branch->id, 'closing_id' => $sentWeek->id, 'received_in_closing_id' => $sentWeek->id, 'status' => 'received', 'received_at' => now()]);
        $unsentBox = CashBox::factory()->create(['branch_id' => $branch->id, 'closing_id' => $openWeek->id, 'received_in_closing_id' => $openWeek->id, 'status' => 'received', 'received_at' => now()]);

        $migration->up();

        $this->assertDatabaseHas('cash_boxes', ['id' => $sentBox->id, 'sent_at' => now()->subDay(), 'sent_by' => $head->id]);
        $this->assertDatabaseHas('cash_boxes', ['id' => $unsentBox->id, 'sent_at' => null, 'sent_by' => null]);
        $this->assertTrue($head->fresh()->hasPermission(PermissionKey::SendCashBoxesToAudit));
    }
}
