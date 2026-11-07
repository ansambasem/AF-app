<?php

namespace Tests\Feature\Lifecycle;

use App\Enums\CashBoxType;
use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\ClosingSetting;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Notifications\BranchClosingNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * One week of a branch's collections, from the first payment to the final
 * audit of the week: two collectors fill cash, transfer and clearing boxes,
 * the branch reviews the transfers and clearings, closes some boxes by hand
 * (one at a time and several of a type at once, leaving the collectors
 * nothing to confirm) and leaves the rest to the weekly cut-off, and the
 * auditor reviews the transfers and approves the whole week at once. The
 * subscribers' statements and the financial log are checked against it.
 */
class CashBoxClosingAuditJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_week_of_collections_is_closed_reviewed_and_audited_into_the_subscribers_balances(): void
    {
        $this->travelTo('2026-10-15 08:00:00');
        ClosingSetting::company()->update(['week_starts_on' => 6]);
        $branch = Branch::factory()->create();
        $head = User::factory()->branchAdmin()->for($branch)->create();
        $accountant = User::factory()->accountant()->for($branch)->withPermissions([PermissionKey::CloseCashBoxes])->create();
        $firstCollector = User::factory()->collector()->for($branch)->withPermissions([PermissionKey::AddClearings])->create();
        $secondCollector = User::factory()->collector()->for($branch)->create();
        $auditor = User::factory()->financialAuditor()->create();
        $first = Subscription::factory()->for($branch)->create();
        $second = Subscription::factory()->for($branch)->create();
        SubscriptionTransaction::factory()->for($first)->create(['amount' => '500.00']);
        SubscriptionTransaction::factory()->for($second)->create(['amount' => '300.00']);
        Notification::fake();

        $firstCash = $this->pay($firstCollector, $first, '100');
        $firstTransfer = $this->pay($firstCollector, $first, '200', 'T-ONE');
        $this->actingAs($firstCollector)->post(route('subscriptions.clearings.store', $second), ['amount' => '30', 'notes' => 'صيانة خط'])->assertSessionHasNoErrors();
        $clearing = $second->transactions()->where('type', SubscriptionTransaction::TYPE_CLEARING)->sole();
        $secondTransfer = $this->pay($secondCollector, $second, '150', 'T-TWO');
        $secondCash = $this->pay($secondCollector, $second, '50');
        $this->assertSame(5, CashBox::query()->where('status', 'open')->count());
        $this->assertSame([CashBoxType::Cash, CashBoxType::BankTransfer, CashBoxType::Clearing], [$firstCash->boxEntry->box->type, $firstTransfer->boxEntry->box->type, $clearing->boxEntry->box->type]);

        $this->closingAction($head, 'electronic', $firstTransfer->boxEntry->id, ['status' => 'confirmed'])->assertSessionHasNoErrors();
        $this->closingAction($head, 'electronic', $secondTransfer->boxEntry->id, ['status' => 'missing'])->assertSessionHasNoErrors();
        $this->closingAction($head, 'electronic', $clearing->boxEntry->id, ['status' => 'rejected'])->assertSessionHasNoErrors();
        $this->closingAction($head, 'electronic', $firstCash->boxEntry->id, ['status' => 'missing'])->assertSessionHasErrors('action');
        Notification::assertSentTo($head, BranchClosingNotification::class);
        $this->assertSame(['cancel'], $clearing->fresh()->availableActions($head));
        $this->actingAs($head)->post(route('subscriptions.transactions.actions.store', [$second, $clearing]), [
            'action' => 'cancel', 'correction_reason' => 'other', 'correction_notes' => 'المقاصة لم تتم',
        ])->assertSessionHasNoErrors();
        $this->assertSame(100.0, $second->balance());

        $this->closingAction($accountant, 'receive-type', null, ['type' => 'cash', 'box_ids' => [$firstCash->boxEntry->cash_box_id, $secondCash->boxEntry->cash_box_id]])->assertSessionHasNoErrors();
        $this->closingAction($firstCollector, 'confirm', $firstCash->boxEntry->cash_box_id)->assertSessionHasErrors('action');
        $this->closingAction($head, 'receive', $firstTransfer->boxEntry->cash_box_id)->assertSessionHasNoErrors();
        $this->closingAction($head, 'receive', $clearing->boxEntry->cash_box_id)->assertSessionHasNoErrors();
        $this->closingAction($head, 'receive', $clearing->boxEntry->cash_box_id)->assertSessionHasErrors('action');
        $this->assertSame(['completed', 'completed'], [$firstCash->boxEntry->box->fresh()->stage(), $secondCash->boxEntry->box->fresh()->stage()]);
        $this->assertSame([], $firstCash->fresh()->availableActions($head));
        $this->assertSame([], $firstTransfer->fresh()->availableActions($head));
        $lateCash = $this->pay($firstCollector, $first, '10');
        $this->assertNotSame($firstCash->boxEntry->cash_box_id, $lateCash->boxEntry->cash_box_id);
        $this->assertSame(190.0, $first->balance());

        $this->travelTo('2026-10-16 20:59:59');
        $this->artisan('closings:process')->assertSuccessful();
        $this->assertSame('open', $secondTransfer->boxEntry->box->fresh()->status);
        $this->travelTo('2026-10-16 21:00:00');
        $this->artisan('closings:process')->assertSuccessful();
        foreach ([$secondTransfer, $lateCash] as $payment) {
            $box = $payment->boxEntry->box->fresh();
            $this->assertSame(['received', CashBox::CLOSE_REASON_PERIOD_END, null], [$box->status, $box->close_reason, $box->received_by]);
        }

        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $firstTransfer->boxEntry), ['bank_amount' => '180', 'reason' => 'وصل أقل من المسجل'])->assertSessionHasNoErrors();
        $this->post(route('payments.bank-audits.store', $secondTransfer->boxEntry), ['bank_amount' => '0', 'reason' => 'لم تصل الحوالة'])->assertSessionHasNoErrors();
        $this->post(route('payments.bank-audits.store', $secondTransfer->boxEntry), ['bank_amount' => '5'])->assertSessionHasErrors('action');
        $this->assertSame([190.0, 100.0], [$first->balance(), $second->balance()]);
        $preview = $this->get(route('payments.cash-boxes', ['branch_id' => $branch->id, 'place' => 'closed', 'week' => '2026-10-10']))->inertiaProps('periodAudit');
        $this->assertSame([6, 2, 2, 0, '170.00', '0.00'], [$preview['boxes'], $preview['transfers'], $preview['exceptions'], $preview['automaticMatches'], $preview['debit'], $preview['credit']]);
        $boxesBeforeApproval = CashBox::query()->orderBy('id')->get()->map->getAttributes()->all();
        $approval = [...array_intersect_key($preview, array_flip(['branch_id', 'week', 'box_ids', 'snapshot'])), 'confirmed' => true];

        $this->post(route('payments.period-audits.store'), $approval)->assertSessionHasNoErrors();
        $this->post(route('payments.period-audits.store'), $approval)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('cash_box_audits', 6);
        $this->assertSame($boxesBeforeApproval, CashBox::query()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame([210.0, 250.0], [$first->balance(), $second->balance()]);
        $this->post(route('payments.bank-audits.store', $firstTransfer->boxEntry), ['bank_amount' => '200', 'reason' => 'تغيير القرار'])->assertSessionHasErrors('action');
        $this->assertSame(210.0, $first->balance());
        $this->actingAs($head)->get(route('subscriptions.statement', $first))->assertInertia(fn ($page) => $page
            ->where('summary.balance', '210.00')
            ->where('summary.paid', '290.00')
            ->where('entries', function ($entries): bool {
                $adjustment = $entries->last();
                $this->assertSame(['حركة تصحيح', [], false], [$adjustment['typeLabel'], $adjustment['available_actions'], $adjustment['isCredit']]);
                $this->assertStringContainsString('حوالة وصلت 180 بدل 200', $adjustment['description']);

                return true;
            }));
        $this->get(route('subscriptions.statement', $second))->assertInertia(fn ($page) => $page
            ->where('entries', fn ($entries): bool => $entries->last()['description'] === 'حوالة لم تصل للبنك؛ تحميل 150 شيكل على المشترك.'));
        $this->get(route('ledger.index', ['period' => 'all']))->assertInertia(fn ($page) => $page
            ->where('summary.collected', 340)
            ->where('ledgerTotals.net', 460)
            ->where('ledgerTotals.cancelledCount', 1));
    }

    private function pay(User $collector, Subscription $subscription, string $amount, ?string $reference = null): SubscriptionTransaction
    {
        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), [
            'amount' => $amount, 'currency' => 'ILS', 'payment_method' => $reference === null ? 'cash' : 'bank_transfer',
            'bank_name' => 'بنك فلسطين', 'sender_name' => 'صاحب الحساب', 'reference_number' => $reference,
        ])->assertSessionHasNoErrors();

        return $subscription->transactions()->where('type', SubscriptionTransaction::TYPE_PAYMENT)->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function closingAction(User $actor, string $action, ?int $record, array $data = []): TestResponse
    {
        return $this->actingAs($actor)->post(route('payments.cash-boxes.actions', array_filter(['action' => $action, 'record' => $record])), [
            'branch_id' => $actor->branch_id, ...$data,
        ]);
    }
}
