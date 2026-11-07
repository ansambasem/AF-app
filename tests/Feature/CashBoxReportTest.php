<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\BankTransferAudit;
use App\Models\CashBox;
use App\Models\CashBoxPayment;
use App\Models\PrintTemplate;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashBoxReportTest extends TestCase
{
    use RefreshDatabase;

    private function entry(CashBox $box, string $amount, array $attributes = [], string $currency = 'ILS'): CashBoxPayment
    {
        $subscriber = Subscription::factory()->create(['branch_id' => $box->branch_id, 'subscription_name' => 'أحمد للتجارة']);
        $transaction = SubscriptionTransaction::withoutEvents(fn (): SubscriptionTransaction => SubscriptionTransaction::factory()->for($subscriber)->create([
            'branch_id' => $box->branch_id, 'recorded_by' => $box->user_id, 'type' => 'payment',
            'amount' => '-'.$amount, 'currency' => $currency, 'currency_amount' => $amount,
            'exchange_rate' => '1', 'payment_method' => 'bank_transfer', 'status' => 'active',
            'reference_number' => 'REF-'.fake()->unique()->numerify('########'), ...$attributes,
        ]));

        return CashBoxPayment::factory()->for($box, 'box')->for($transaction, 'transaction')->create([
            'currency' => $currency, 'amount' => $amount, 'method' => 'bank_transfer',
        ]);
    }

    public function test_bank_report_separates_missing_from_unaudited_and_excludes_returns_from_totals(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(10, 0));
        $owner = User::factory()->collector()->create();
        $box = CashBox::factory()->bankTransfer()->create(['user_id' => $owner->id, 'branch_id' => $owner->branch_id, 'status' => 'received', 'closed_at' => now()]);
        $short = $this->entry($box, '100');
        BankTransferAudit::factory()->for($short, 'entry')->create(['recorded_amount' => '100', 'bank_amount' => '80', 'difference' => '20', 'outcome' => 'short', 'posted_at' => now()]);
        $missing = $this->entry($box, '40');
        BankTransferAudit::factory()->for($missing, 'entry')->create(['recorded_amount' => '40', 'bank_amount' => '0', 'difference' => '40', 'outcome' => 'missing']);
        $unknown = $this->entry($box, '30');
        $returned = $this->entry($box, '25', ['status' => 'linked_cancellation', 'cancellation_reason' => 'payment_refunded', 'cancellation_notes' => 'طلب المشترك إرجاع الدفعة']);
        BankTransferAudit::factory()->for($returned, 'entry')->create(['bank_amount' => '25']);

        $this->actingAs($owner)->get(route('payments.cash-boxes.report', ['box' => $box->id, 'print' => 1, 'per_page' => 1, 'page' => 5]))->assertInertia(fn ($page) => $page
            ->component('Payments/CashBoxReport')
            ->where('report.collector', $owner->name)->where('report.number', $box->number)
            ->where('report.openedAt', $box->opened_at->toIso8601String())
            ->where('report.closedAt', $box->closed_at->toIso8601String())
            ->where('report.counts', ['active' => 3, 'mismatched' => 2, 'returned' => 1, 'all' => 4])
            ->has('report.rows', 3)->where('report.rows.0.subscriber', 'أحمد للتجارة')
            ->where('report.rows.0.reference', $short->transaction->reference_number)
            ->where('report.rows.0.date', $short->occurred_at->toIso8601String())
            ->where('report.rows.0.difference', '20.00')->where('report.rows.0.auditFinal', true)
            ->where('report.rows.1.bankAmount', '0.00')->where('report.rows.1.status', 'غير موجودة')
            ->where('report.rows.2.id', $unknown->id)->where('report.rows.2.bankAmount', null)->where('report.rows.2.difference', null)
            ->missing('report.rows.0.subscriberNumber')
            ->where('report.totals.ILS', ['amount' => '170.00', 'bankAmount' => '80.00', 'difference' => '60.00', 'unknown' => 1]));
        $this->get(route('payments.cash-boxes.report', ['box' => $box->id, 'filter' => 'mismatched']))->assertInertia(fn ($page) => $page
            ->has('report.rows', 2)->where('report.totals.ILS.amount', '140.00'));
        $this->get(route('payments.cash-boxes.report', ['box' => $box->id, 'filter' => 'returned']))->assertInertia(fn ($page) => $page
            ->has('report.rows', 1)->where('report.rows.0.id', $returned->id)
            ->where('report.rows.0.reason', 'طلب المشترك إرجاع الدفعة')->where('report.totals', []));
        $this->get(route('payments.cash-boxes.report', ['box' => $box->id, 'filter' => 'all']))->assertInertia(fn ($page) => $page
            ->has('report.rows', 4)->where('report.totals.ILS.amount', '170.00')->where('report.totals.ILS.bankAmount', '80.00'));
        $this->assertSame(4, $box->payments()->count());
        $this->assertSame('linked_cancellation', $returned->transaction->fresh()->status);
    }

    public function test_currencies_stay_separate_and_overpayments_have_a_negative_difference(): void
    {
        $owner = User::factory()->collector()->create();
        $box = CashBox::factory()->bankTransfer()->create(['user_id' => $owner->id, 'branch_id' => $owner->branch_id]);
        $this->entry($box, '100');
        $dollars = $this->entry($box, '20', [], 'USD');
        BankTransferAudit::factory()->for($dollars, 'entry')->create(['currency' => 'USD', 'recorded_amount' => '20', 'bank_amount' => '25', 'outcome' => 'over']);

        $this->actingAs($owner)->get(route('payments.cash-boxes.report', ['box' => $box->id]))->assertInertia(fn ($page) => $page
            ->where('report.totals.ILS.amount', '100.00')->where('report.totals.ILS.bankAmount', null)
            ->where('report.totals.USD', ['amount' => '20.00', 'bankAmount' => '25.00', 'difference' => '-5.00', 'unknown' => 0]));
    }

    public function test_collectors_can_only_print_their_own_boxes_and_branch_admins_only_their_branch(): void
    {
        $head = User::factory()->branchAdmin()->create();
        $owner = User::factory()->collector()->for($head->branch)->create();
        $colleague = User::factory()->collector()->for($head->branch)->create();
        $box = CashBox::factory()->create(['user_id' => $owner->id, 'branch_id' => $head->branch_id]);
        $foreign = CashBox::factory()->create();

        $this->actingAs($colleague)->get(route('payments.cash-boxes.report', ['box' => $box->id]))->assertNotFound();
        $this->actingAs($head)->get(route('payments.cash-boxes.report', ['box' => $box->id]))->assertOk();
        $this->get(route('payments.cash-boxes.report', ['box' => $foreign->id]))->assertNotFound();
        $this->actingAs($colleague)->get(route('payments.cash-boxes.report'))->assertInertia(fn ($page) => $page->where('report.boxId', null));
    }

    public function test_auditors_can_print_closed_boxes_across_branches_but_cannot_print_open_boxes(): void
    {
        $auditor = User::factory()->financialAuditor()->create();
        $box = CashBox::factory()->bankTransfer()->create(['status' => 'received']);
        $open = CashBox::factory()->bankTransfer()->create();
        $pending = CashBox::factory()->create(['status' => 'pending', 'branch_id' => $auditor->branch_id]);
        $closed = CashBox::factory()->create(['status' => 'closed']);
        $this->actingAs($auditor)->get(route('payments.cash-boxes.report', ['box' => $box->id]))->assertOk();
        $this->get(route('payments.cash-boxes.report', ['box' => $open->id]))->assertNotFound();
        $this->get(route('payments.cash-boxes.report', ['box' => $pending->id]))->assertNotFound();
        $this->get(route('payments.cash-boxes.report', ['box' => $closed->id]))->assertNotFound();
    }

    public function test_financial_permission_allows_foreign_closed_boxes_and_keeps_local_receiving_access(): void
    {
        $reviewer = User::factory()->branchAdmin()->withPermissions([PermissionKey::AuditClosings])->create();
        $local = CashBox::factory()->create(['branch_id' => $reviewer->branch_id]);
        $foreign = CashBox::factory()->create(['status' => 'received']);
        $foreignOpen = CashBox::factory()->create();
        $this->actingAs($reviewer)->get(route('payments.cash-boxes.report', ['box' => $local->id]))->assertOk();
        $this->get(route('payments.cash-boxes.report', ['box' => $foreign->id]))->assertOk();
        $this->get(route('payments.cash-boxes.report', ['box' => $foreignOpen->id]))->assertNotFound();
        $this->actingAs(User::factory()->superAdmin()->create())->get(route('payments.cash-boxes.report', ['box' => $foreignOpen->id]))->assertOk();
    }

    public function test_report_requires_authentication_and_valid_filters(): void
    {
        $this->get(route('payments.cash-boxes.report'))->assertRedirect(route('login'));
        $owner = User::factory()->collector()->create();
        $this->actingAs($owner)->getJson(route('payments.cash-boxes.report', ['filter' => 'invalid']))->assertUnprocessable()->assertJsonValidationErrors('filter');
        $this->getJson(route('payments.cash-boxes.report', ['box' => 'invalid']))->assertUnprocessable()->assertJsonValidationErrors('box');
        $unprivileged = User::factory()->create(['role' => 'data_entry']);
        $this->actingAs($unprivileged)->get(route('payments.cash-boxes.report'))->assertForbidden();
    }

    public function test_box_designs_can_be_saved_shared_and_opened_without_a_box(): void
    {
        $manager = User::factory()->superAdmin()->create();
        $layout = [
            'paper' => 'A3', 'orientation' => 'landscape',
            'columns' => [['key' => 'تاريخ الحركة', 'label' => 'التاريخ', 'visible' => true]],
            'header' => ['reportFields' => ['number', 'collector']],
            'footer' => ['signatures' => ['المحصّل', 'المدقق']],
        ];
        $this->actingAs($manager)->post(route('print-templates.store'), [
            'page' => '/payments/cash-boxes/report', 'name' => 'تقرير الصندوق', 'layout' => $layout, 'is_default' => true,
        ])->assertSessionHasNoErrors();
        $template = PrintTemplate::query()->sole();
        $this->assertSame($layout, $template->layout);
        $owner = User::factory()->collector()->create();
        $this->actingAs($owner)->get(route('payments.cash-boxes.report', ['print' => 1]))->assertInertia(fn ($page) => $page
            ->where('report.boxId', null)->has('report.rows', 0)
            ->where('printTemplates.0.name', 'تقرير الصندوق')->where('printTemplates.0.isDefault', true)
            ->where('printTemplates.0.layout.header.reportFields', ['number', 'collector'])
            ->where('can.managePrintTemplates', false));
        $this->post(route('print-templates.store'), [
            'page' => '/payments/cash-boxes/report', 'name' => 'غير مسموح', 'layout' => $layout,
        ])->assertForbidden();
    }
}
