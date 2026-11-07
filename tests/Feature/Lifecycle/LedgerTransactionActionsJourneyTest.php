<?php

namespace Tests\Feature\Lifecycle;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\TransactionDeletion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each of the seven actions on a subscriber's lines, applied the way the
 * statement opened over the financial log offers it — editing a charge's
 * amount, editing a transfer's details, refunding a cash payment, cancelling
 * a penalty, deleting that cancellation, deleting the refunded payment with
 * its refund, and deleting the last line — with the log's figures and rows
 * after every one.
 */
class LedgerTransactionActionsJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_statement_action_leaves_the_financial_log_in_step_with_the_balance(): void
    {
        $this->travelTo('2026-10-20 08:00:00');
        $branch = Branch::factory()->create();
        $head = User::factory()->branchAdmin()->for($branch)->withPermissions([PermissionKey::ForceDeleteTransactions])->create();
        $collector = User::factory()->collector()->for($branch)->create();
        $subscription = Subscription::factory()->for($branch)->create();
        SubscriptionTransaction::factory()->for($subscription)->create(['amount' => '100.00']);
        $this->actingAs($head)->post(route('subscriptions.charges.store', $subscription), ['type' => 'penalty', 'amount' => '40', 'notes' => 'تلاعب بالعداد'])->assertSessionHasNoErrors();
        $penalty = $subscription->transactions()->where('type', 'penalty')->sole();

        $this->act($head, $penalty, ['edit', 'delete'], ['action' => 'edit', 'amount' => '45', 'amendment_reason' => 'المبلغ الصحيح']);
        $this->assertLog($head, $subscription, charged: 145, credited: 0, cancelledCount: 0, rows: 2);

        $this->travel(1)->minute();
        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), ['amount' => '30', 'currency' => 'ILS', 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $cash = $subscription->transactions()->where('payment_method', 'cash')->sole();
        $this->travel(1)->minute();
        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), [
            'amount' => '50', 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'المرسل', 'reference_number' => 'LEDGER-1',
        ])->assertSessionHasNoErrors();
        $transfer = $subscription->transactions()->where('payment_method', 'bank_transfer')->sole();
        $this->act($head, $transfer, ['edit_metadata', 'delete'], ['action' => 'edit_metadata', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'المرسل الصحيح', 'notes' => 'حوالة الشهر', 'amendment_reason' => 'اسم المرسل']);
        $this->assertSame('المرسل الصحيح', $transfer->fresh()->sender_name);
        $this->assertSame(1, $transfer->amendments()->count());
        $this->assertLog($head, $subscription, charged: 145, credited: 80, cancelledCount: 0, rows: 4);

        $this->travel(1)->minute();
        $this->act($head, $cash, ['edit_metadata', 'refund'], ['action' => 'refund']);
        $this->assertSame(SubscriptionTransaction::STATUS_LINKED_CANCELLATION, $cash->fresh()->status);
        $this->assertLog($head, $subscription, charged: 145, credited: 50, cancelledCount: 1, rows: 5, cancelledRows: 2);

        $this->travel(1)->minute();
        $this->act($head, $penalty, ['cancel'], ['action' => 'cancel', 'correction_reason' => 'other', 'correction_notes' => 'أُعفي من الغرامة']);
        $this->assertLog($head, $subscription, charged: 100, credited: 50, cancelledCount: 2, rows: 6, cancelledRows: 4);

        $cancellation = $subscription->transactions()->where('type', SubscriptionTransaction::TYPE_CANCELLATION)->sole();
        $this->act($head, $cancellation, ['delete_reversal'], ['action' => 'delete_reversal', 'correction_notes' => 'الإعفاء سُجّل خطأ']);
        $this->assertSame(SubscriptionTransaction::STATUS_ACTIVE, $penalty->fresh()->status);
        $this->assertLog($head, $subscription, charged: 145, credited: 50, cancelledCount: 1, rows: 5, cancelledRows: 2);

        $this->act($head, $cash, ['delete_tree'], ['action' => 'delete_tree', 'correction_notes' => 'دفعة أُدخلت على الحساب الخطأ']);
        $this->assertModelMissing($cash);
        $this->assertLog($head, $subscription, charged: 145, credited: 50, cancelledCount: 0, rows: 3);

        $this->travel(1)->minute();
        $this->actingAs($head)->post(route('subscriptions.discounts.store', $subscription), ['method' => 'shekel', 'value' => '10'])->assertSessionHasNoErrors();
        $discount = $subscription->transactions()->where('type', SubscriptionTransaction::TYPE_DISCOUNT)->sole();
        $this->assertLog($head, $subscription, charged: 145, credited: 60, cancelledCount: 0, rows: 4);
        $this->act($head, $discount, ['delete'], ['action' => 'delete', 'correction_notes' => 'خصم مكرر']);

        $this->assertLog($head, $subscription, charged: 145, credited: 50, cancelledCount: 0, rows: 3);
        $this->assertSame(['delete_reversal', 'delete_tree', 'delete'], TransactionDeletion::query()->orderBy('id')->pluck('action')->all());
        $this->assertSame(['100.00', '145.00', '95.00'], $subscription->transactions()->orderBy('created_at')->orderBy('id')->pluck('balance_after')->all());
    }

    /**
     * Apply an action from the line's menu on the statement, which must offer
     * exactly `$offered`.
     *
     * @param  list<string>  $offered
     * @param  array<string, mixed>  $payload
     */
    private function act(User $actor, SubscriptionTransaction $line, array $offered, array $payload): void
    {
        $this->actingAs($actor)->get(route('ledger.index', ['statement' => $line->subscription_id]))->assertInertia(fn ($page) => $page
            ->where('statement.entries', fn ($entries): bool => $entries->firstWhere('id', $line->id)['available_actions'] === $offered));

        $this->post(route('subscriptions.transactions.actions.store', [$line->subscription_id, $line]), $payload)->assertSessionHasNoErrors();
    }

    private function assertLog(User $actor, Subscription $subscription, float $charged, float $credited, int $cancelledCount, int $rows, int $cancelledRows = 0): void
    {
        $this->assertSame(round($charged - $credited, 2), $subscription->balance());
        $this->actingAs($actor)->get(route('ledger.index', ['period' => 'all']))->assertInertia(fn ($page) => $page
            ->where('ledgerTotals.charged', fn ($total): bool => (float) $total === $charged)
            ->where('ledgerTotals.credited', fn ($total): bool => (float) $total === $credited)
            ->where('ledgerTotals.net', fn ($total): bool => (float) $total === round($charged - $credited, 2))
            ->where('ledgerTotals.cancelledCount', $cancelledCount)
            ->where('entries.total', $rows)
            ->where('entries.data', fn ($entries): bool => $entries->where('isCancelled', true)->count() === $cancelledRows));
        $this->get(route('ledger.index', ['period' => 'all', 'filter' => ['show_cancelled' => '0']]))->assertInertia(fn ($page) => $page
            ->where('entries.total', $rows - $cancelledRows));
    }
}
