<?php

namespace Tests\Feature;

use App\Models\BankTransferAudit;
use App\Models\CashBox;
use App\Models\CashBoxAudit;
use App\Models\ClosingSetting;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BankTransferAuditing;
use App\Support\BranchClosingWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class PeriodAuditTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, User, Subscription, SubscriptionTransaction, CashBox} */
    private function closedPayment(?User $collector = null): array
    {
        $this->travelTo(now()->setDate(2026, 10, 18)->setTime(10, 0));
        ClosingSetting::company()->update(['week_starts_on' => 6]);
        $collector ??= User::factory()->collector()->create();
        $head = User::factory()->branchAdmin()->create(['branch_id' => $collector->branch_id]);
        $auditor = User::factory()->financialAuditor()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id]);
        SubscriptionTransaction::factory()->for($subscription)->create(['amount' => '200.00']);
        $payment = SubscriptionTransaction::recordPayment($subscription, $collector, [
            'amount' => '100', 'currency' => 'ILS', 'exchange_rate' => '1', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'reference_number' => 'BANK-'.$subscription->id,
        ]);
        $box = $payment->boxEntry->box;
        app(BranchClosingWorkflow::class)->receive($box, $head);

        return [$collector, $auditor, $subscription, $payment, $box];
    }

    /** @return array<string, mixed> */
    private function preview(User $auditor, int $branchId): array
    {
        return $this->actingAs($auditor)->get(route('payments.cash-boxes', [
            'branch_id' => $branchId, 'place' => 'closed', 'week' => '2026-10-17',
        ]))->assertOk()->inertiaProps('periodAudit');
    }

    /** @return array<string, mixed> */
    private function approval(array $preview): array
    {
        return [...array_intersect_key($preview, array_flip(['branch_id', 'week', 'box_ids', 'snapshot'])), 'confirmed' => true];
    }

    public function test_period_approval_posts_only_exceptions_and_matches_the_rest_across_all_selected_week_boxes(): void
    {
        [$collector, $auditor, $subscription, $payment, $box] = $this->closedPayment();
        [, , $secondSubscription, $secondPayment, $secondBox] = $this->closedPayment($collector);
        $cash = CashBox::factory()->create(['branch_id' => $box->branch_id, 'status' => 'received', 'closed_at' => now()]);
        $outsideBranch = CashBox::factory()->create(['status' => 'received', 'closed_at' => now()]);
        $outsideWeek = CashBox::factory()->create(['branch_id' => $box->branch_id, 'status' => 'received', 'closed_at' => now()->subWeek()]);
        $open = CashBox::factory()->create(['branch_id' => $box->branch_id]);
        $original = $payment->fresh()->getAttributes();
        app(BankTransferAuditing::class)->record($payment->boxEntry, $auditor, '50', 'نقص في كشف البنك');
        $preview = $this->preview($auditor, $box->branch_id);
        $this->assertSame(3, $preview['boxes']);
        $this->assertSame(2, $preview['transfers']);
        $this->assertSame(1, $preview['exceptions']);
        $this->assertSame(1, $preview['automaticMatches']);
        $this->assertSame('50.00', $preview['debit']);
        $this->assertSame(100.0, $subscription->balance());
        $payload = $this->approval($preview);

        $this->post(route('payments.period-audits.store'), $payload)->assertSessionHasNoErrors();
        $this->post(route('payments.period-audits.store'), $payload)->assertSessionHasNoErrors();

        $this->assertSame(150.0, $subscription->balance());
        $this->assertSame(100.0, $secondSubscription->balance());
        $this->assertSame($original, $payment->fresh()->getAttributes());
        $this->assertDatabaseCount('cash_box_audits', 3);
        $this->assertSame(2, BankTransferAudit::query()->whereNotNull('posted_at')->count());
        $this->assertDatabaseHas('bank_transfer_audits', ['cash_box_payment_id' => $secondPayment->boxEntry->id, 'outcome' => 'matched']);
        foreach ([$outsideBranch, $outsideWeek, $open] as $untouched) {
            $this->assertNull($untouched->audit);
        }
        $this->get(route('payments.cash-boxes', ['branch_id' => $box->branch_id, 'place' => 'closed', 'week' => $preview['week']]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('periodAudit.boxes', 0)->where('periods.0.reviewed', 3));
    }

    #[TestWith(['draft'])]
    #[TestWith(['new_box'])]
    #[TestWith(['individual_approval'])]
    public function test_changed_periods_require_a_fresh_preview_without_posting_any_additional_effect(string $change): void
    {
        [$collector, $auditor, $subscription, $payment, $box] = $this->closedPayment();
        $payload = $this->approval($this->preview($auditor, $box->branch_id));
        if ($change === 'draft') {
            app(BankTransferAuditing::class)->record($payment->boxEntry, $auditor, '0', 'لم تصل');
        } elseif ($change === 'new_box') {
            $this->closedPayment($collector);
        } else {
            $this->closedPayment($collector);
            $payload = $this->approval($this->preview($auditor, $box->branch_id));
            app(BankTransferAuditing::class)->complete($box, $auditor);
        }
        $transactionsBefore = SubscriptionTransaction::query()->count();
        $stampsBefore = CashBoxAudit::query()->count();

        $this->post(route('payments.period-audits.store'), $payload)->assertSessionHasErrors('action');

        $this->assertSame($stampsBefore, CashBoxAudit::query()->count());
        $this->assertSame($transactionsBefore, SubscriptionTransaction::query()->count());
        $this->assertSame(100.0, $subscription->balance());
    }

    public function test_a_failure_in_one_box_rolls_back_every_box_and_financial_posting_in_the_period(): void
    {
        [$collector, $auditor, $subscription, $payment, $box] = $this->closedPayment();
        [, , , $second] = $this->closedPayment($collector);
        foreach ([$payment, $second] as $entry) {
            app(BankTransferAuditing::class)->record($entry->boxEntry, $auditor, '50', 'نقص');
        }
        $payload = $this->approval($this->preview($auditor, $box->branch_id));
        $listener = function (SubscriptionTransaction $transaction) use ($second): void {
            if ($transaction->type === SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT && $transaction->reference_transaction_id === $second->id) {
                throw new RuntimeException('Posting failed');
            }
        };
        SubscriptionTransaction::creating($listener);
        try {
            $this->post(route('payments.period-audits.store'), $payload)->assertServerError();
        } finally {
            SubscriptionTransaction::flushEventListeners();
            SubscriptionTransaction::clearBootedModels();
        }
        $this->assertDatabaseCount('cash_box_audits', 0);
        $this->assertSame(0, BankTransferAudit::query()->whereNotNull('posted_at')->count());
        $this->assertDatabaseCount('subscription_transactions', 4);
        $this->assertSame(100.0, $subscription->balance());
    }

    #[TestWith(['confirmed', false, 'confirmed'])]
    #[TestWith(['week', '2026-10-18', 'action'])]
    #[TestWith(['snapshot', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'action'])]
    #[TestWith(['box_ids', [], 'box_ids'])]
    public function test_unconfirmed_or_incorrect_period_submissions_do_not_change_financial_records(string $field, mixed $value, string $error): void
    {
        [, $auditor, $subscription, , $box] = $this->closedPayment();
        $payload = $this->approval($this->preview($auditor, $box->branch_id));
        $payload[$field] = $value;
        $this->post(route('payments.period-audits.store'), $payload)->assertSessionHasErrors($error);
        $this->assertDatabaseCount('cash_box_audits', 0);
        $this->assertDatabaseCount('bank_transfer_audits', 0);
        $this->assertSame(100.0, $subscription->balance());
    }

    public function test_cross_branch_box_ids_are_rejected_even_for_an_auditor_with_access_to_all_branches(): void
    {
        [, $auditor, , , $box] = $this->closedPayment();
        [, , , , $otherBox] = $this->closedPayment();
        $payload = $this->approval($this->preview($auditor, $box->branch_id));
        $payload['box_ids'][] = $otherBox->id;
        $this->post(route('payments.period-audits.store'), $payload)->assertSessionHasErrors('action');
        $this->assertDatabaseCount('cash_box_audits', 0);
    }

    public function test_only_authorized_active_auditors_can_approve_a_period_and_each_box_requires_explicit_confirmation(): void
    {
        [, $auditor, , , $box] = $this->closedPayment();
        $this->post(route('payments.period-audits.store'))->assertRedirect(route('login'));
        $payload = $this->approval($this->preview($auditor, $box->branch_id));
        $this->post(route('payments.box-audits.store', $box))->assertSessionHasErrors('confirmed');
        $this->post(route('payments.period-audits.store'), array_diff_key($payload, ['confirmed' => true]))->assertSessionHasErrors('confirmed');
        $this->actingAs(User::factory()->accountant()->create())->post(route('payments.period-audits.store'), $payload)->assertForbidden();
        $auditor->update(['is_active' => false]);
        $this->actingAs($auditor)->post(route('payments.period-audits.store'), $payload)->assertRedirect(route('login'));
        $this->assertDatabaseCount('cash_box_audits', 0);
    }
}
