<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class BankAuditReportingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, Subscription, SubscriptionTransaction, User} */
    private function auditPayment(string $verified, ?string $auditAt = null): array
    {
        $this->travelTo('2026-10-18 10:00:00');
        $branch = Branch::factory()->create();
        $head = User::factory()->branchAdmin()->for($branch)->create();
        $collector = User::factory()->collector()->for($branch)->create();
        $auditor = User::factory()->financialAuditor()->for($branch)->create();
        $subscription = Subscription::factory()->for($branch)->create();
        SubscriptionTransaction::factory()->for($subscription)->create(['amount' => '200.00']);
        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), [
            'amount' => '100', 'currency' => 'ILS', 'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك فلسطين', 'sender_name' => 'صاحب الحساب', 'reference_number' => 'BANK-REPORT',
        ])->assertSessionHasNoErrors();
        $payment = $subscription->transactions()->where('type', SubscriptionTransaction::TYPE_PAYMENT)->sole();
        $box = $payment->boxEntry->box;
        $this->actingAs($head)->post(route('payments.cash-boxes.actions', ['action' => 'receive', 'record' => $box->id]), ['branch_id' => $branch->id])->assertSessionHasNoErrors();
        if ($auditAt !== null) {
            $this->travelTo($auditAt);
        }
        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $payment->boxEntry), ['bank_amount' => $verified, 'reason' => 'مطابقة كشف البنك'])->assertSessionHasNoErrors();
        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();

        return [$head, $subscription, $payment, $auditor];
    }

    #[TestWith(['0', '300.00', '100.00', '200.00'])]
    #[TestWith(['50', '250.00', '100.00', '150.00'])]
    #[TestWith(['100', '200.00', '100.00', '100.00'])]
    #[TestWith(['150', '200.00', '150.00', '50.00'])]
    public function test_report_debit_and_credit_totals_follow_the_side_of_a_final_bank_adjustment(string $verified, string $charges, string $credits, string $balance): void
    {
        [$head, $subscription] = $this->auditPayment($verified);
        $this->assertSame((float) $balance, $subscription->balance());
        $report = $this->actingAs($head)->get(route('reports.index', ['branch' => $head->branch_id, 'from' => '2026-10-18', 'to' => '2026-10-18']));
        $report->assertInertia(fn (AssertableInertia $page) => $page->where('flow.closing', $balance));

        $report->assertInertia(fn (AssertableInertia $page) => $page->where('flow.chargesTotal', $charges)->where('flow.creditsTotal', $credits)
            ->where('days.0.charges', $charges)->where('days.0.payments', $credits));
    }

    public function test_charge_only_reports_exclude_a_credit_created_by_an_excess_bank_receipt(): void
    {
        [$head, $subscription, $payment] = $this->auditPayment('150');
        $adjustment = $payment->boxEntry->audit->adjustment;
        $this->assertTrue($adjustment->isCredit());
        $this->assertSame(50.0, $subscription->balance());

        $this->actingAs($head)->get(route('reports.index', ['branch' => $head->branch_id, 'from' => '2026-10-18', 'to' => '2026-10-18', 'kind' => 'charges']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('transactions.data', function ($entries) use ($adjustment): bool {
                $this->assertNotContains($adjustment->id, $entries->pluck('id')->all());

                return true;
            }));
    }

    public function test_daily_ledger_breakdown_agrees_with_the_overall_credit_and_debit_totals_after_an_excess_bank_receipt(): void
    {
        [$head, $subscription] = $this->auditPayment('150');
        $this->assertSame(50.0, $subscription->balance());
        $ledger = $this->actingAs($head)->get(route('ledger.index', ['period' => 'today']));
        $ledger->assertInertia(fn (AssertableInertia $page) => $page->where('ledgerTotals.charged', 200)->where('ledgerTotals.credited', 150)->where('ledgerTotals.net', 50));

        $ledger->assertInertia(fn (AssertableInertia $page) => $page->where('dayTotals.2026-10-18.charged', 200)->where('dayTotals.2026-10-18.credited', 150));
    }

    #[TestWith(['0', 100, 0])]
    #[TestWith(['50', 50, 0])]
    #[TestWith(['150', 0, 50])]
    public function test_branch_team_figures_classify_the_auditors_recorded_adjustment_on_its_correct_side(string $verified, int $charged, int $credited): void
    {
        [$head, , , $auditor] = $this->auditPayment($verified);

        $this->actingAs($head)->get(route('branch-performance.show', $head->branch_id))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('team', function ($team) use ($auditor, $charged, $credited): bool {
                $figures = $team->firstWhere('id', $auditor->id);
                $this->assertSame(1, $figures['recordedCount']);
                $this->assertSame($charged, $figures['recordedCharged']);
                $this->assertSame($credited, $figures['recordedCredited']);

                return true;
            }));
    }

    public function test_a_later_audit_credit_does_not_rewrite_the_original_days_financial_report(): void
    {
        [$head, $subscription, $payment] = $this->auditPayment('150', '2026-10-19 10:00:00');
        $original = $payment->fresh()->getAttributes();

        $this->actingAs($head)->get(route('reports.index', ['from' => '2026-10-18', 'to' => '2026-10-18']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('flow.chargesTotal', '200.00')->where('flow.creditsTotal', '100.00')->where('flow.closing', '100.00'));
        $this->get(route('reports.index', ['from' => '2026-10-19', 'to' => '2026-10-19']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('flow.opening', '100.00')->where('flow.chargesTotal', '0.00')->where('flow.creditsTotal', '50.00')->where('flow.closing', '50.00'));
        $this->assertSame($original, $payment->fresh()->getAttributes());
        $this->assertSame(50.0, $subscription->balance());
    }
}
