<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\PermissionKey;
use App\Models\BankTransferAudit;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashBoxAudit;
use App\Models\MobileAccessToken;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BankTransferAuditing;
use App\Support\BranchClosingWorkflow;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class BankTransferAuditTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, User, Subscription, SubscriptionTransaction, CashBox} */
    private function payment(bool $closed = true, string $currency = 'ILS', string $rate = '1', string $method = 'bank_transfer', string $amount = '100'): array
    {
        $this->travelTo(now()->setDate(2026, 10, 18)->setTime(10, 0));
        $branch = Branch::factory()->create();
        $head = User::factory()->branchAdmin()->for($branch)->create();
        $collector = User::factory()->collector()->for($branch)->create();
        $auditor = User::factory()->financialAuditor()->for(Branch::factory()->create(['name' => 'الإدارة الرئيسية']))->create();
        $subscription = Subscription::factory()->for($branch)->create();
        SubscriptionTransaction::factory()->for($subscription)->create(['amount' => '200.00']);
        $payment = SubscriptionTransaction::recordPayment($subscription, $collector, [
            'amount' => $amount, 'currency' => $currency, 'exchange_rate' => $rate, 'payment_method' => $method,
            'bank_name' => 'بنك فلسطين', 'reference_number' => 'BANK-100',
        ]);
        $box = $payment->boxEntry->box;
        if ($closed) {
            app(BranchClosingWorkflow::class)->receive($box, $head);
        }

        return [$head, $auditor, $subscription, $payment, $box];
    }

    #[TestWith(['0', '100.00', 'missing', 200.0])]
    #[TestWith(['50', '50.00', 'short', 150.0])]
    #[TestWith(['100', '0.00', 'matched', 100.0])]
    #[TestWith(['150', '-50.00', 'over', 50.0])]
    #[TestWith(['250', '-150.00', 'over', -50.0])]
    public function test_only_final_box_approval_posts_the_difference_and_preserves_the_closed_original(string $bankAmount, string $difference, string $outcome, float $balance): void
    {
        [$head, $auditor, $subscription, $payment, $box] = $this->payment();
        $before = $payment->fresh()->getAttributes();
        $boxBefore = $box->fresh()->getAttributes();
        $entryBefore = $payment->boxEntry()->firstOrFail()->getAttributes();

        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $payment->boxEntry), ['bank_amount' => $bankAmount, 'reason' => 'مطابقة كشف البنك'])->assertSessionHasNoErrors();

        $draft = BankTransferAudit::query()->sole();
        $this->assertNull($draft->posted_at);
        $this->assertNull($draft->adjustment_transaction_id);
        $this->assertDatabaseCount('subscription_transactions', 2);
        $this->assertSame(100.0, $subscription->balance());
        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();

        $audit = BankTransferAudit::query()->sole();
        $this->assertNotNull($audit->posted_at);
        $this->assertSame($auditor->id, $audit->audited_by);
        $this->assertSame($outcome, $audit->outcome);
        $this->assertSame($difference, $audit->difference);
        $this->assertSame($balance, $subscription->balance());
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertSame($boxBefore, $box->fresh()->getAttributes());
        $this->assertSame($entryBefore, $payment->boxEntry()->firstOrFail()->getAttributes());
        $this->assertSame('100.00', $box->fresh()->totals()['ILS']);
        $this->assertDatabaseCount('cash_boxes', 1);
        $this->assertDatabaseCount('cash_box_payments', 1);
        if ($difference === '0.00') {
            $this->assertNull($audit->adjustment_transaction_id);
            $this->assertDatabaseCount('subscription_transactions', 2);
        } else {
            $adjustment = $audit->adjustment;
            $this->assertSame($difference, $adjustment->amount);
            $this->assertSame($payment->id, $adjustment->reference_transaction_id);
            $this->assertSame($payment->branch_id, $adjustment->branch_id);
            $this->assertSame($auditor->id, $adjustment->recorded_by);
            $this->assertSame(SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT, $adjustment->type);
            $this->assertSame([], $adjustment->availableActions($head));
            $this->assertSame((float) $difference < 0, $adjustment->isCredit());
            $this->assertSame((float) $difference < 0, SubscriptionTransaction::query()->credits()->whereKey($adjustment)->exists());
            $this->assertSame((float) $difference > 0, SubscriptionTransaction::query()->charges()->whereKey($adjustment)->exists());
        }
        $this->actingAs($head)->get(route('subscriptions.statement', $subscription))->assertInertia(fn ($page) => $page
            ->where('summary.paid', number_format((float) $bankAmount, 2, '.', ''))
            ->where('summary.charged', '200.00')
            ->where('summary.paymentsCount', 1));
    }

    public function test_foreign_currency_difference_uses_the_payments_original_rate(): void
    {
        [, $auditor, $subscription, $payment, $box] = $this->payment(currency: 'USD', rate: '3.7');

        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $payment->boxEntry), ['bank_amount' => '50', 'reason' => 'نقص في الحوالة'])->assertSessionHasNoErrors();
        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();

        $audit = BankTransferAudit::query()->sole();
        $this->assertSame('185.00', $audit->difference);
        $this->assertSame('50.00', $audit->adjustment->currency_amount);
        $this->assertSame(Currency::Dollar, $audit->adjustment->currency);
        $this->assertSame('3.7000', $audit->adjustment->exchange_rate);
        $this->assertSame(15.0, $subscription->balance());
        $this->assertSame('370.00', $box->fresh()->totals()['ILS']);
    }

    #[TestWith(['0', 'حوالة لم تصل للبنك؛ تحميل 150 شيكل على المشترك.'])]
    #[TestWith(['100', 'حوالة وصلت 100 بدل 150 شيكل؛ تحميل فرق 50 شيكل.'])]
    #[TestWith(['200', 'حوالة وصلت 200 بدل 150 شيكل؛ إضافة فرق 50 شيكل لصالح المشترك.'])]
    public function test_subscriber_statement_explains_the_audit_difference_with_a_short_correction_description(string $bankAmount, string $description): void
    {
        [$head, $auditor, $subscription, $payment, $box] = $this->payment(amount: '150');
        $before = $payment->fresh()->getAttributes();
        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $payment->boxEntry), ['bank_amount' => $bankAmount, 'reason' => 'مطابقة كشف البنك'])->assertSessionHasNoErrors();
        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();

        $this->actingAs($head)->get(route('subscriptions.statement', $subscription))->assertInertia(fn ($page) => $page
            ->where('entries.1.description', 'دفعة بتحويل بنكي')
            ->where('entries.2.typeLabel', 'حركة تصحيح')
            ->where('entries.2.description', $description));
        $this->assertSame($before, $payment->fresh()->getAttributes());
    }

    public function test_foreign_currency_description_distinguishes_the_transfer_currency_from_the_shekel_difference(): void
    {
        [$head, $auditor, $subscription, $payment, $box] = $this->payment(currency: 'USD', rate: '3.7');
        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $payment->boxEntry), ['bank_amount' => '50', 'reason' => 'نقص'])->assertSessionHasNoErrors();
        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();

        $this->actingAs($head)->get(route('subscriptions.statement', $subscription))->assertInertia(fn ($page) => $page
            ->where('entries.2.description', 'حوالة وصلت 50 بدل 100 دولار؛ تحميل فرق 185 شيكل.'));
    }

    public function test_drafts_can_be_revised_but_a_final_approval_cannot_be_replaced_or_posted_twice(): void
    {
        [, $auditor, $subscription, $payment, $box] = $this->payment();
        $otherAuditor = User::factory()->financialAuditor()->create();
        $url = route('payments.bank-audits.store', $payment->boxEntry);
        $this->actingAs($auditor)->post($url, ['bank_amount' => '50', 'reason' => 'نقص'])->assertSessionHasNoErrors();
        $before = BankTransferAudit::query()->sole()->getAttributes();
        $this->post($url, ['bank_amount' => '50', 'reason' => 'نقص'])->assertSessionHasNoErrors();
        $this->assertSame($before, BankTransferAudit::query()->sole()->getAttributes());
        $this->actingAs($otherAuditor)->post($url, ['bank_amount' => '0', 'reason' => 'لم تصل'])->assertSessionHasNoErrors();
        $this->assertSame(100.0, $subscription->balance());
        $this->assertDatabaseCount('subscription_transactions', 2);
        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();
        $before = BankTransferAudit::query()->sole()->getAttributes();
        $this->post($url, ['bank_amount' => '0', 'reason' => 'إعادة إرسال'])->assertSessionHasNoErrors();
        $this->post($url, ['bank_amount' => '100'])->assertSessionHasErrors('action');
        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('bank_transfer_audits', 1);
        $this->assertDatabaseCount('subscription_transactions', 3);
        $this->assertSame($before, BankTransferAudit::query()->sole()->getAttributes());
        $this->assertSame(200.0, $subscription->balance());
        $this->assertSame($otherAuditor->id, BankTransferAudit::query()->sole()->audited_by);
    }

    #[TestWith(['branchAdmin'])]
    #[TestWith(['collector'])]
    #[TestWith(['accountant'])]
    public function test_users_without_audit_permission_cannot_post_decisions(string $role): void
    {
        [, , , $payment] = $this->payment();
        $actor = User::factory()->{$role}()->create(['branch_id' => $payment->branch_id]);
        $this->actingAs($actor)->post(route('payments.bank-audits.store', $payment->boxEntry), ['bank_amount' => '0', 'reason' => 'رفض'])->assertForbidden();
        $this->post(route('payments.box-audits.store', $payment->boxEntry->box), ['confirmed' => true])->assertForbidden();
        $this->assertDatabaseCount('bank_transfer_audits', 0);
        $this->assertDatabaseCount('subscription_transactions', 2);
    }

    #[TestWith(['-1'])]
    #[TestWith(['1.001'])]
    #[TestWith(['1000001'])]
    #[TestWith(['abc'])]
    public function test_invalid_bank_amounts_create_no_audit_or_financial_effect(string $amount): void
    {
        [, $auditor, , $payment] = $this->payment();
        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $payment->boxEntry), ['bank_amount' => $amount, 'reason' => 'تدقيق'])->assertSessionHasErrors('bank_amount');
        $this->assertDatabaseCount('bank_transfer_audits', 0);
        $this->assertDatabaseCount('subscription_transactions', 2);
    }

    #[TestWith([false, 'bank_transfer'])]
    #[TestWith([true, 'cash'])]
    public function test_open_boxes_and_cash_entries_cannot_receive_bank_adjustments(bool $closed, string $method): void
    {
        [, $auditor, , $payment] = $this->payment(closed: $closed, method: $method);
        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $payment->boxEntry), ['bank_amount' => '0', 'reason' => 'تدقيق'])->assertSessionHasErrors('action');
        $this->assertDatabaseCount('bank_transfer_audits', 0);
    }

    public function test_a_difference_requires_a_reason_and_failure_leaves_no_partial_result(): void
    {
        [, $auditor, $subscription, $payment] = $this->payment();
        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $payment->boxEntry), ['bank_amount' => '0'])->assertSessionHasErrors('action');
        $this->assertSame(100.0, $subscription->balance());
        $this->assertDatabaseCount('bank_transfer_audits', 0);
        $this->assertDatabaseCount('subscription_transactions', 2);
    }

    public function test_final_approval_matches_unreviewed_transfers_without_changing_the_box_history(): void
    {
        [, $auditor, , $payment, $box] = $this->payment();
        $before = $box->fresh()->getAttributes();
        $this->actingAs($auditor)->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();
        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('cash_box_audits', 1);
        $this->assertSame($before, $box->fresh()->getAttributes());
        $this->assertSame($auditor->id, CashBoxAudit::query()->sole()->audited_by);
        $audit = BankTransferAudit::query()->sole();
        $this->assertSame('matched', $audit->outcome);
        $this->assertNotNull($audit->posted_at);
        $this->assertNull($audit->adjustment_transaction_id);
        $this->assertDatabaseCount('subscription_transactions', 2);
        $this->assertRefused(fn () => $audit->update(['bank_amount' => '0']));
        $this->get(route('payments.cash-boxes', ['branch_id' => $box->branch_id]))->assertInertia(fn ($page) => $page
            ->where('boxes.0.audit.auditor.name', $auditor->name)
            ->where('boxes.0.auditSummary.reviewed', 1)
            ->where('boxes.0.auditSummary.verified', '100.00')
            ->where('periods.0.count', 1)->where('periods.0.reviewed', 1)
            ->where('boxes.0.canAudit', false));
    }

    public function test_auditors_access_closed_boxes_and_subscriber_accounts_across_branches(): void
    {
        [, $auditor, $subscription, $payment, $box] = $this->payment();
        $auditor->permissions()->syncWithoutDetaching(Permission::idsFor([PermissionKey::ViewCollections, PermissionKey::ViewSubscriptions, PermissionKey::ViewAllFinancialReports, PermissionKey::CloseCashBoxes]));
        $this->actingAs($auditor)->get(route('dashboard'))->assertOk();
        $this->get(route('payments.cash-boxes', ['branch_id' => $box->branch_id, 'place' => 'open']))->assertInertia(fn ($page) => $page
            ->where('can.auditOnly', false)->where('place', 'closed')
            ->where('branches', fn ($branches): bool => collect($branches)->pluck('id')->contains($box->branch_id) && collect($branches)->pluck('id')->contains($auditor->branch_id))
            ->where('boxes.0.id', $box->id)->where('boxes.0.payments.0.statement_url', route('subscriptions.statement', [$subscription, 'transaction' => $payment->id]))
            ->where('boxes.0.canAudit', true));
        $this->get(route('subscriptions.payments.receipt', [$subscription, $payment]))->assertOk();
        $this->get(route('profile.edit'))->assertOk();
        foreach (['ledger.index', 'subscriptions.index', 'transaction-audit.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('reports.index'))->assertOk();
        $this->get(route('payments.index'))->assertForbidden();
        $this->get(route('payments.cash-boxes.entries', ['branch_id' => $box->branch_id]))->assertForbidden();
        $this->get(route('payments.cash-boxes.entries'))->assertInertia(fn ($page) => $page
            ->where('branch', null)->where('branchScope.branches', 1)
            ->where('branchScope.name', $auditor->branch->name)->where('entries.total', 0));
        $this->post(route('payments.cash-boxes.actions', ['action' => 'receive', 'record' => $box->id]), ['branch_id' => $box->branch_id])->assertNotFound();
    }

    public function test_final_box_approval_posts_three_exceptions_and_matches_the_other_ninety_seven_transfers_once(): void
    {
        [$head, $auditor, $subscription, $first, $box] = $this->payment(closed: false);
        $approver = User::factory()->financialAuditor()->create();
        $payments = [$first];
        for ($index = 1; $index < 100; $index++) {
            $payments[] = SubscriptionTransaction::recordPayment($subscription, $first->recordedBy, [
                'amount' => '100', 'currency' => 'ILS', 'exchange_rate' => '1', 'payment_method' => 'bank_transfer',
                'bank_name' => 'بنك فلسطين', 'reference_number' => 'BANK-BULK-'.$index,
            ]);
        }
        app(BranchClosingWorkflow::class)->receive($box, $head);
        $boxBefore = $box->fresh()->getAttributes();
        $this->actingAs($auditor);
        foreach (['0', '50', '150'] as $index => $amount) {
            $this->post(route('payments.bank-audits.store', $payments[$index]->boxEntry), ['bank_amount' => $amount, 'reason' => 'استثناء في كشف البنك'])->assertSessionHasNoErrors();
        }
        $this->assertSame(-9800.0, $subscription->balance());
        $this->assertDatabaseCount('subscription_transactions', 101);

        $this->actingAs($approver)->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();
        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();

        $this->assertSame(-9700.0, $subscription->balance());
        $this->assertDatabaseCount('bank_transfer_audits', 100);
        $this->assertDatabaseCount('subscription_transactions', 104);
        $this->assertDatabaseCount('cash_box_audits', 1);
        $this->assertSame(97, BankTransferAudit::query()->where('outcome', 'matched')->count());
        $this->assertSame(100, BankTransferAudit::query()->whereNotNull('posted_at')->count());
        $this->assertSame(3, SubscriptionTransaction::query()->where('type', SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT)->where('recorded_by', $approver->id)->count());
        $this->assertSame(3, BankTransferAudit::query()->where('audited_by', $auditor->id)->count());
        $this->assertSame($boxBefore, $box->fresh()->getAttributes());
        $this->assertSame('10000.00', $box->fresh()->totals()['ILS']);
    }

    public function test_failed_final_posting_rolls_back_all_adjustments_and_keeps_the_saved_drafts(): void
    {
        [$head, $auditor, $subscription, $first, $box] = $this->payment(closed: false);
        $second = SubscriptionTransaction::recordPayment($subscription, $first->recordedBy, [
            'amount' => '100', 'currency' => 'ILS', 'exchange_rate' => '1', 'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك فلسطين', 'reference_number' => 'BANK-SECOND',
        ]);
        app(BranchClosingWorkflow::class)->receive($box, $head);
        $this->actingAs($auditor);
        foreach ([$first, $second] as $payment) {
            $this->post(route('payments.bank-audits.store', $payment->boxEntry), ['bank_amount' => '0', 'reason' => 'لم تصل'])->assertSessionHasNoErrors();
        }
        $adjustments = 0;
        SubscriptionTransaction::creating(function (SubscriptionTransaction $transaction) use (&$adjustments): void {
            if ($transaction->type === SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT && ++$adjustments === 2) {
                throw new RuntimeException('Posting unavailable.');
            }
        });

        try {
            app(BankTransferAuditing::class)->complete($box, $auditor);
            $this->fail('A failed posting must not approve any part of the box.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Posting unavailable.', $exception->getMessage());
        }

        $this->assertSame(0.0, $subscription->balance());
        $this->assertDatabaseCount('subscription_transactions', 3);
        $this->assertDatabaseCount('cash_box_audits', 0);
        $this->assertDatabaseCount('bank_transfer_audits', 2);
        $this->assertSame(0, BankTransferAudit::query()->whereNotNull('posted_at')->orWhereNotNull('adjustment_transaction_id')->count());
    }

    public function test_existing_decisions_are_marked_as_already_posted_when_the_draft_workflow_is_installed(): void
    {
        $audit = BankTransferAudit::factory()->create();
        $transactionsBefore = SubscriptionTransaction::query()->count();
        $migration = require database_path('migrations/2026_10_18_133709_add_posted_at_to_bank_transfer_audits_table.php');
        $migration->down();

        $migration->up();

        $audit->refresh();
        $this->assertSame($audit->getRawOriginal('audited_at'), $audit->getRawOriginal('posted_at'));
        $this->assertSame($transactionsBefore, SubscriptionTransaction::query()->count());
        $this->assertTrue($audit->isPosted());
        $this->assertRefused(fn () => $audit->update(['bank_amount' => '0']));
    }

    public function test_final_approval_preserves_an_adjustment_already_posted_under_the_previous_workflow(): void
    {
        [, $auditor, $subscription, $payment, $box] = $this->payment();
        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $payment->boxEntry), ['bank_amount' => '50', 'reason' => 'نقص'])->assertSessionHasNoErrors();
        $adjustment = SubscriptionTransaction::factory()->for($subscription)->create([
            'type' => SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT, 'amount' => '50.00',
            'branch_id' => $payment->branch_id, 'recorded_by' => $auditor->id,
            'reference_transaction_id' => $payment->id,
            'source_key' => 'bank-audit:'.$payment->boxEntry->id,
        ]);
        $audit = BankTransferAudit::query()->sole();
        $audit->update(['adjustment_transaction_id' => $adjustment->id, 'posted_at' => now()->subMinute()]);
        $before = $audit->getAttributes();

        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();

        $this->assertSame($before, $audit->fresh()->getAttributes());
        $this->assertSame(150.0, $subscription->balance());
        $this->assertDatabaseCount('subscription_transactions', 3);
        $this->assertDatabaseCount('cash_box_audits', 1);
    }

    public function test_bulk_matching_uses_the_original_foreign_amount_without_creating_an_adjustment(): void
    {
        [, $auditor, $subscription, , $box] = $this->payment(currency: 'USD', rate: '3.7');

        $this->actingAs($auditor)->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();

        $audit = BankTransferAudit::query()->sole();
        $this->assertSame('USD', $audit->currency);
        $this->assertSame('100.00', $audit->bank_amount);
        $this->assertSame('0.00', $audit->difference);
        $this->assertNull($audit->adjustment_transaction_id);
        $this->assertSame(-170.0, $subscription->balance());
        $this->assertDatabaseCount('subscription_transactions', 2);
    }

    public function test_audit_adjustment_and_decision_cannot_be_edited_or_deleted(): void
    {
        [$head, $auditor, $subscription, $payment, $box] = $this->payment();
        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $payment->boxEntry), ['bank_amount' => '50', 'reason' => 'نقص'])->assertSessionHasNoErrors();
        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();
        $audit = BankTransferAudit::query()->sole();
        $adjustment = $audit->adjustment;
        $this->assertRefused(fn () => $adjustment->updateQuietly(['amount' => '1']));
        $this->assertRefused(fn () => $adjustment->deleteQuietly());
        $this->assertRefused(fn () => $audit->update(['bank_amount' => '100']));
        $this->assertRefused(fn () => $audit->delete());
        $this->actingAs($head)->delete(route('subscriptions.transactions.force-destroy', [$subscription, $adjustment]), ['correction_notes' => 'حذف'])->assertForbidden();
        $this->assertSame('50.00', $adjustment->fresh()->amount);
    }

    public function test_period_completion_counts_all_closed_boxes_in_the_branch(): void
    {
        [, $auditor, , $payment, $box] = $this->payment();
        $cashBox = CashBox::factory()->create(['branch_id' => $box->branch_id, 'status' => 'received', 'closed_at' => now()]);
        $url = route('payments.cash-boxes', ['branch_id' => $box->branch_id]);
        $this->actingAs($auditor)->post(route('payments.box-audits.store', $cashBox), ['confirmed' => true])->assertSessionHasNoErrors();
        $this->get($url)->assertInertia(fn ($page) => $page->where('periods.0.count', 2)->where('periods.0.reviewed', 1));
        $this->post(route('payments.bank-audits.store', $payment->boxEntry), ['bank_amount' => '100'])->assertSessionHasNoErrors();
        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();
        $this->get($url)->assertInertia(fn ($page) => $page->where('periods.0.count', 2)->where('periods.0.reviewed', 2));
    }

    public function test_auditors_cannot_use_mobile_endpoints_even_with_an_old_token(): void
    {
        $auditor = User::factory()->financialAuditor()->create();
        $this->postJson(route('mobile.login'), ['username' => $auditor->username, 'password' => 'password'])->assertForbidden();
        $this->assertDatabaseCount('mobile_access_tokens', 0);
        $token = MobileAccessToken::issue($auditor);
        $this->withToken($token)->getJson(route('mobile.me'))->assertForbidden();
    }

    private function assertRefused(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Approved audit history must be immutable.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }
}
