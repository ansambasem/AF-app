<?php

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\CorrectionReason;
use App\Enums\DiscountMethod;
use App\Enums\PermissionKey;
use App\Enums\TransactionAction;
use App\Models\Branch;
use App\Models\Closing;
use App\Models\ClosingSetting;
use App\Models\MeterReading;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BranchReportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $north;

    private Branch $south;

    private Subscription $subscription;

    private User $collector;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.business_timezone' => 'Asia/Gaza']);
        $this->travelTo(Carbon::parse('2026-10-01 10:00', 'Asia/Gaza'));
        $this->north = Branch::factory()->create(['name' => 'North']);
        $this->south = Branch::factory()->create(['name' => 'South']);
        $this->subscription = Subscription::factory()->create(['branch_id' => $this->north->id]);
        $this->collector = User::factory()->collector()->create(['name' => 'Sami', 'branch_id' => $this->north->id]);
    }

    public function test_the_days_flow_adds_up_from_what_was_owed_to_what_is_owed(): void
    {
        $this->at('2026-09-30 09:00', fn () => SubscriptionTransaction::recordCharge($this->subscription, $this->collector, ChargeType::Penalty, '100', 'late'));
        $this->at('2026-10-01 08:00', fn () => SubscriptionTransaction::recordCharge($this->subscription, $this->collector, ChargeType::DisconnectionFee, '30', null));
        $this->at('2026-10-01 08:10', fn () => $this->pay('40'));
        $this->at('2026-10-01 08:20', fn () => $this->pay('25', ['payment_method' => 'bank_transfer', 'bank_name' => 'Bank of Palestine', 'reference_number' => 'T-1']));
        $this->at('2026-10-01 08:30', fn () => SubscriptionTransaction::recordDiscount($this->subscription, $this->collector, DiscountMethod::Shekel, '5', null));
        $mistake = $this->at('2026-10-01 08:40', fn () => $this->pay('15'));
        $this->at('2026-10-01 08:45', fn () => $mistake->cancel($this->collector, CorrectionReason::Duplicate, null));
        $this->at('2026-10-01 09:00', fn () => $this->pay('999', [], Subscription::factory()->create(['branch_id' => $this->south->id])));

        $this->actingAs($this->preparer())->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->component('Reports/Index')
            ->where('filters', ['branch' => $this->north->id, 'from' => '2026-10-01', 'to' => '2026-10-01', 'kind' => 'all'])
            ->where('flow.opening', '100.00')
            ->where('flow.charges', [['type' => 'disconnection_fee', 'label' => __(ChargeType::DisconnectionFee->label()), 'count' => 1, 'total' => '30.00']])
            ->where('flow.creditsTotal', '70.00')
            ->where('flow.corrections', ['count' => 1, 'total' => '0.00'])
            ->where('flow.closing', '60.00')
            ->where('collections.total', '65.00')
            ->where('collections.cash', '40.00')
            ->where('collections.accounts', [
                ['key' => 'cash', 'label' => 'الصندوق النقدي', 'count' => 1, 'total' => '40.00'],
                ['key' => 'Bank of Palestine', 'label' => 'Bank of Palestine', 'count' => 1, 'total' => '25.00'],
            ])
            ->where('collections.collectors', [['name' => 'Sami', 'count' => 2, 'total' => '65.00', 'cash' => '40.00']])
            ->missing('check')
            ->has('transactions.data', 6));
    }

    public function test_a_past_period_keeps_its_figures_when_a_line_is_cancelled_later(): void
    {
        $payment = $this->at('2026-09-29 10:00', fn () => $this->pay('80'));
        $this->at('2026-09-30 10:00', fn () => $payment->cancel($this->collector, CorrectionReason::NotReceived, null));
        $this->actingAs($this->preparer());

        $this->get(route('reports.index', ['from' => '2026-09-29', 'to' => '2026-09-29']))->assertInertia(fn ($page) => $page
            ->where('flow.creditsTotal', '80.00')
            ->where('flow.corrections.total', '0.00')
            ->where('flow.closing', '-80.00'));
        $this->get(route('reports.index', ['from' => '2026-09-29', 'to' => '2026-09-30']))->assertInertia(fn ($page) => $page
            ->where('flow.creditsTotal', '80.00')
            ->where('flow.corrections.total', '80.00')
            ->where('flow.closing', '0.00')
            ->where('days', fn ($days): bool => collect($days)->map(fn ($day) => [$day['day'], $day['payments'], $day['corrections'], $day['balance']])->all() === [
                ['2026-09-29', '80.00', '0.00', '-80.00'],
                ['2026-09-30', '0.00', '80.00', '0.00'],
            ]));
    }

    public function test_historical_closings_do_not_expose_controls_or_change_report_totals(): void
    {
        $this->at('2026-09-30 10:00', fn () => $this->pay('120'));
        $closing = Closing::factory()->approved()->forDay('2026-09-30')->create(['branch_id' => $this->north->id]);
        $before = $closing->fresh()->getAttributes();
        $this->at('2026-09-30 11:00', fn () => $this->pay('30'));

        $this->actingAs($this->preparer())->get(route('reports.index', ['from' => '2026-09-30', 'to' => '2026-09-30']))
            ->assertInertia(fn ($page) => $page
                ->missing('check')
                ->missing('days.0.closing')
                ->where('collections.cash', '150.00')
                ->where('days.0.payments', '150.00')
                ->where('flow.closing', '-150.00'));

        $this->assertSame($before, $closing->fresh()->getAttributes());
    }

    public function test_the_readings_entered_approved_and_still_pending_are_counted(): void
    {
        MeterReading::factory()->for($this->subscription)->create(['consumption' => 40]);
        MeterReading::factory()->for($this->subscription)->approved()->create(['week_start' => '2026-09-18', 'week_end' => '2026-09-24', 'consumption' => 10, 'amount_due' => '25.50', 'approved_at' => now()]);
        MeterReading::factory()->for(Subscription::factory()->create(['branch_id' => $this->south->id]))->create();

        $this->actingAs($this->preparer())->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('readings', ['entered' => 2, 'consumption' => 50, 'approved' => 1, 'billed' => '25.50', 'pending' => 1]));
    }

    public function test_a_preparer_reports_only_on_their_own_branch_and_company_report_permission_reaches_all(): void
    {
        $this->at('2026-10-01 08:00', fn () => $this->pay('10'));
        $this->at('2026-10-01 08:00', fn () => $this->pay('20', [], Subscription::factory()->create(['branch_id' => $this->south->id])));

        $this->actingAs($this->preparer())->get(route('reports.index', ['branch' => $this->south->id]))->assertInertia(fn ($page) => $page
            ->where('filters.branch', $this->north->id)
            ->where('branchScope', null)
            ->where('scopeLabel', 'North')
            ->where('collections.total', '10.00'));
        $this->get(route('reports.index', ['branch' => 'all']))->assertInertia(fn ($page) => $page->where('collections.total', '10.00'));
        $this->assertStringNotContainsString('South', $this->get(route('reports.export'))->streamedContent());

        $auditor = User::factory()->accountant()->create(['branch_id' => $this->north->id]);
        $auditor->permissions()->sync(Permission::idsFor([PermissionKey::ViewAllFinancialReports, PermissionKey::ExportFinancialReports]));

        $this->actingAs($auditor)->get(route('reports.index', ['branch' => 'all']))->assertInertia(fn ($page) => $page
            ->where('filters.branch', 'all')
            ->where('scopeLabel', 'كل الفروع')
            ->where('branchScope', null)
            ->where('collections.total', '30.00'));

        $this->actingAs(User::factory()->superAdmin()->create())->get(route('reports.index', ['branch' => 'all']))->assertInertia(fn ($page) => $page
            ->where('branchScope.all', true)
            ->where('branchScope.name', 'جميع الفروع')
            ->where('branchScope.branches', Branch::count())
            ->where('branchScope.subscribers', Subscription::count()));
        $this->get(route('reports.index', ['branch' => $this->north->id]))->assertInertia(fn ($page) => $page
            ->where('branchScope.all', false)
            ->where('branchScope.name', 'North')
            ->where('branchScope.subscribers', 1));
    }

    public function test_a_report_across_branches_counts_each_branchs_payments_by_its_own_cutoff(): void
    {
        ClosingSetting::create(['branch_id' => $this->north->id, 'cutoff_time' => '18:00', 'auto_open' => true]);
        $southSubscription = Subscription::factory()->create(['branch_id' => $this->south->id]);
        // North closes its day at 18:00, so anything it takes from then on counts for the next day; South closes at midnight.
        $this->at('2026-09-29 17:00', fn () => $this->pay('50'));
        $this->at('2026-09-29 18:30', fn () => $this->pay('11'));
        $this->at('2026-09-30 10:00', fn () => $this->pay('100'));
        $this->at('2026-09-30 19:00', fn () => $this->pay('20'));
        $this->at('2026-09-30 19:00', fn () => $this->pay('7', [], $southSubscription));
        $this->at('2026-10-01 08:00', fn () => $this->pay('3', [], $southSubscription));

        $auditor = User::factory()->accountant()->create(['branch_id' => $this->north->id]);
        $auditor->permissions()->sync(Permission::idsFor([PermissionKey::ViewAllFinancialReports, PermissionKey::ExportFinancialReports]));

        $this->actingAs($auditor)->get(route('reports.index', ['branch' => 'all', 'from' => '2026-09-30', 'to' => '2026-10-01']))->assertInertia(fn ($page) => $page
            // North's 50 was taken on its 29 Sep, before the period; South's 7 on 30 Sep.
            ->where('flow.opening', '-50.00')
            ->where('collections.total', '141.00')
            ->where('flow.closing', '-191.00')
            ->where('days', fn ($days): bool => collect($days)->map(fn ($day) => [$day['day'], $day['payments']])->all() === [
                ['2026-09-30', '118.00'],
                ['2026-10-01', '23.00'],
            ])
            ->has('transactions.data', 5));

        $this->get(route('reports.index', ['branch' => $this->north->id, 'from' => '2026-09-30', 'to' => '2026-09-30']))->assertInertia(fn ($page) => $page
            ->where('cutoff', '18:00')
            ->where('collections.total', '111.00'));
        $this->get(route('reports.index', ['branch' => $this->south->id, 'from' => '2026-09-30', 'to' => '2026-09-30']))->assertInertia(fn ($page) => $page
            ->where('cutoff', '00:00')
            ->where('collections.total', '7.00'));
    }

    public function test_someone_who_cannot_open_the_closings_cannot_open_the_reports(): void
    {
        $this->actingAs($this->collector)->get(route('reports.index'))->assertForbidden();
        $this->get(route('reports.export'))->assertForbidden();
    }

    public function test_cancellations_and_refunds_count_with_the_corrections_and_never_as_charges(): void
    {
        $penalty = $this->at('2026-10-01 08:00', fn () => SubscriptionTransaction::recordCharge($this->subscription, $this->collector, ChargeType::Penalty, '100', 'late'));
        $payment = $this->at('2026-10-01 08:10', fn () => $this->pay('40'));
        $head = User::factory()->branchAdmin()->create(['branch_id' => $this->north->id]);
        $this->at('2026-10-01 08:20', fn () => $penalty->applyAction($head, TransactionAction::Cancel, ['correction_reason' => 'other', 'correction_notes' => 'إعفاء']));
        $this->at('2026-10-01 08:30', fn () => $payment->applyAction($head, TransactionAction::Refund, []));
        $this->actingAs($this->preparer());

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('flow.corrections', ['count' => 2, 'total' => '0.00'])
            ->where('flow.closing', '0.00'));
        $this->get(route('reports.index', ['kind' => 'charges']))->assertInertia(fn ($page) => $page
            ->where('transactions.data', fn ($lines): bool => collect($lines)->pluck('type')->all() === ['penalty']));
        $this->get(route('reports.index', ['kind' => 'corrections']))->assertInertia(fn ($page) => $page
            ->where('transactions.total', 4));
    }

    public function test_an_opening_credit_balance_counts_off_what_is_owed_on_its_day(): void
    {
        $this->at('2026-10-01 08:00', fn () => $this->subscription->transactions()->create([
            'recorded_by' => $this->collector->id, 'type' => SubscriptionTransaction::TYPE_CREDIT, 'source_key' => 'import:LEGACY-1', 'amount' => '-25.00', 'notes' => 'رصيد افتتاحي من النظام القديم',
        ]));
        $this->at('2026-10-01 08:10', fn () => SubscriptionTransaction::recordCharge($this->subscription, $this->collector, ChargeType::Penalty, '100', 'late'));
        $this->actingAs($this->preparer());

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('flow.chargesTotal', '100.00')
            ->where('flow.creditsTotal', '25.00')
            ->where('days', fn ($days): bool => collect($days)->map(fn ($day) => [$day['charges'], $day['payments'], $day['discounts'], $day['balance']])->all() === [
                ['100.00', '0.00', '25.00', '75.00'],
            ]));
    }

    public function test_the_lines_download_as_csv_narrowed_to_one_kind(): void
    {
        $this->at('2026-10-01 08:00', fn () => SubscriptionTransaction::recordCharge($this->subscription, $this->collector, ChargeType::Penalty, '100', 'late'));
        $payment = $this->at('2026-10-01 08:10', fn () => $this->pay('40'));
        $this->actingAs($this->preparer());

        $response = $this->get(route('reports.export', ['kind' => 'payments']))->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString(rawurlencode('التقارير_North_2026-10-01_2026-10-01.csv'), $response->headers->get('Content-Disposition'));

        $this->assertStringStartsWith("\u{FEFF}\"North — التقارير المالية\"\n\"الفترة: 01/10/2026 – 01/10/2026\"\n\nالتاريخ,الوقت,الفرع", $csv);
        $this->assertStringContainsString($payment->printedVoucherNumber(), $csv);
        $this->assertStringNotContainsString(__(ChargeType::Penalty->label()), $csv);
        $this->get(route('reports.index', ['kind' => 'charges']))->assertInertia(fn ($page) => $page
            ->where('transactions.data', fn ($lines): bool => collect($lines)->pluck('type')->all() === ['penalty']));
    }

    public function test_the_excel_editor_gets_every_line_oldest_first_with_the_pages_filters(): void
    {
        $this->at('2026-10-01 08:00', fn () => SubscriptionTransaction::recordCharge($this->subscription, $this->collector, ChargeType::Penalty, '100', 'late'));
        $first = $this->at('2026-10-01 08:10', fn () => $this->pay('40'));
        $second = $this->at('2026-10-01 09:00', fn () => $this->pay('15.5'));
        $this->at('2026-10-01 09:30', fn () => $this->pay('20', [], Subscription::factory()->create(['branch_id' => $this->south->id])));
        $this->actingAs($this->preparer());

        $this->getJson(route('reports.lines', ['kind' => 'payments', 'branch' => $this->south->id]))
            ->assertOk()
            ->assertJsonCount(2, 'lines')
            ->assertJsonPath('lines.0.id', $first->id)
            ->assertJsonPath('lines.0.amount', '40.00')
            ->assertJsonPath('lines.0.isCredit', true)
            ->assertJsonPath('lines.0.branchName', 'North')
            ->assertJsonPath('lines.1.id', $second->id);
        $this->getJson(route('reports.lines'))->assertJsonCount(3, 'lines');
        $this->getJson(route('reports.lines', ['from' => '2026-10-02']))->assertUnprocessable();

        $this->actingAs($this->collector)->getJson(route('reports.lines'))->assertForbidden();
    }

    public function test_a_period_is_valid_and_at_most_a_quarter_and_never_past_today(): void
    {
        $this->actingAs($this->preparer());

        $this->get(route('reports.index', ['from' => '2026-01-01', 'to' => '2026-09-30']))
            ->assertSessionHasErrors(['from' => 'اختر فترة صحيحة لا تزيد على ثلاثة أشهر ولا تتجاوز اليوم.']);
        $this->get(route('reports.index', ['from' => '2026-09-30', 'to' => '2026-12-31']))
            ->assertInertia(fn ($page) => $page->where('filters.to', '2026-10-01'));
    }

    public function test_comparison_uses_the_same_branch_and_shows_no_percentage_when_the_previous_total_is_zero(): void
    {
        $this->at('2026-09-30 09:00', fn () => $this->pay('40'));
        $this->at('2026-10-01 09:00', fn () => $this->pay('60'));
        $this->at('2026-09-30 09:00', fn () => $this->pay('999', [], Subscription::factory()->create(['branch_id' => $this->south->id])));
        $this->actingAs($this->preparer());

        $this->get(route('reports.index', ['kind' => 'charges']))->assertInertia(fn ($page) => $page
            ->where('comparison', ['from' => '2026-09-30', 'to' => '2026-09-30', 'total' => '40.00', 'difference' => '20.00', 'percentage' => 50])
            ->where('collections.total', '60.00'));
        $this->get(route('reports.index', ['from' => '2026-09-30', 'to' => '2026-09-30']))->assertInertia(fn ($page) => $page
            ->where('comparison.total', '0.00')
            ->where('comparison.difference', '40.00')
            ->where('comparison.percentage', null));
    }

    public function test_excel_lines_are_bounded_and_cursor_pages_do_not_skip_or_duplicate_records(): void
    {
        $lines = SubscriptionTransaction::factory()->count(501)->for($this->subscription)
            ->sequence(fn (Sequence $sequence): array => ['source_key' => 'export-test:'.$sequence->index])->create([
                'recorded_by' => $this->collector->id, 'type' => 'payment',
                'amount' => '-1.00', 'created_at' => now(),
            ]);
        $this->actingAs($this->preparer());

        $first = $this->getJson(route('reports.lines', ['kind' => 'payments']))->assertJsonCount(500, 'lines');
        $second = $this->getJson(route('reports.lines', ['kind' => 'payments', 'cursor' => $first->json('nextCursor')]))
            ->assertJsonCount(1, 'lines')->assertJsonPath('nextCursor', null);

        $this->assertSame($lines->pluck('id')->all(), array_merge(array_column($first->json('lines'), 'id'), array_column($second->json('lines'), 'id')));
    }

    public function test_print_designer_receives_the_chosen_summary_or_filtered_details_with_company_templates(): void
    {
        $payment = $this->at('2026-10-01 08:10', fn () => $this->pay('40'));
        $this->at('2026-10-01 08:20', fn () => SubscriptionTransaction::recordCharge($this->subscription, $this->collector, ChargeType::Penalty, '100', 'late'));
        $this->actingAs($this->preparer());

        $this->get(route('reports.index', ['print' => 1, 'print_view' => 'summary']))->assertInertia(fn ($page) => $page
            ->where('printData.columns.0.label', 'البند')
            ->where('printData.context.branchName', 'North')
            ->where('printData.rows', fn ($rows): bool => collect($rows)->contains(fn ($row): bool => $row['cells']['البند'] === 'إجمالي المحصّل' && $row['cells']['المبلغ ₪'] === '40.00'))
            ->has('printTemplates'));
        $this->get(route('reports.index', ['print' => 1, 'print_all' => 1, 'print_view' => 'lines', 'kind' => 'payments']))->assertInertia(fn ($page) => $page
            ->where('printData.total', 1)
            ->where('printData.rows.0.cells.السند', $payment->printedVoucherNumber())
            ->where('printData.rows.0.cells.له', '40.00')
            ->where('printData.context.templatePage', '/reports'));
    }

    public function test_report_presents_weekly_and_monthly_periods_without_changing_the_filter_shape(): void
    {
        $this->actingAs($this->preparer());

        $this->get(route('reports.index', [
            'from' => '2026-09-26', 'to' => '2026-10-01', 'view' => 'weekly',
        ]))->assertInertia(fn ($page) => $page
            ->where('period.mode', 'weekly')
            ->where('filters', ['branch' => $this->north->id, 'from' => '2026-09-26', 'to' => '2026-10-01', 'kind' => 'all']));

        $this->get(route('reports.index', [
            'from' => '2026-10-01', 'to' => '2026-10-01', 'view' => 'monthly',
        ]))->assertInertia(fn ($page) => $page->where('period.mode', 'monthly'));
    }

    private function preparer(): User
    {
        $user = User::factory()->accountant()->create(['branch_id' => $this->north->id]);
        $user->permissions()->sync(Permission::idsFor([PermissionKey::ViewFinancialReports, PermissionKey::ExportFinancialReports]));

        return $user;
    }

    public function test_historical_debts_keep_later_cancellations_out_and_separate_credit_balances(): void
    {
        $charge = $this->at('2026-06-01 09:00', fn () => SubscriptionTransaction::recordCharge($this->subscription, $this->collector, ChargeType::Penalty, '100', null));
        $this->at('2026-09-29 09:00', fn () => $this->pay('20'));
        $this->at('2026-09-30 09:00', fn () => SubscriptionTransaction::recordCharge($this->subscription, $this->collector, ChargeType::Penalty, '50', null));
        $credited = Subscription::factory()->create(['branch_id' => $this->north->id]);
        $this->at('2026-09-30 09:30', fn () => $this->pay('30', [], $credited));
        $this->at('2026-10-01 09:00', fn () => $charge->cancel($this->collector, CorrectionReason::Duplicate, null));
        $this->at('2026-09-30 10:00', fn () => SubscriptionTransaction::recordCharge(Subscription::factory()->create(['branch_id' => $this->south->id]), $this->collector, ChargeType::Penalty, '999', null));

        $this->actingAs($this->preparer())->get(route('reports.index', ['section' => 'debts', 'from' => '2026-09-30', 'to' => '2026-09-30', 'print' => 1]))->assertInertia(fn ($page) => $page
            ->where('debts.total', '130.00')
            ->where('debts.credit', '-30.00')
            ->where('debts.count', 1)
            ->where('detailTable.rows.0.id', $this->subscription->id)
            ->where('detailTable.rows.0.buckets.current', '50.00')
            ->where('detailTable.rows.0.buckets.older', '80.00')
            ->where('printData.rows.1.cells.الرصيد عليه', '130.00'));
    }

    public function test_report_statement_prints_signed_balances_and_positive_debit_credit_amounts(): void
    {
        $this->at('2026-09-30 09:00', fn () => SubscriptionTransaction::recordCharge($this->subscription, $this->collector, ChargeType::Penalty, '100', null));
        $this->at('2026-10-01 09:00', fn () => $this->pay('150'));
        $owner = User::factory()->superAdmin()->create();

        $this->actingAs($owner)->get(route('reports.index', ['section' => 'statement', 'subscription' => $this->subscription->id, 'print' => 1]))->assertInertia(fn ($page) => $page
            ->where('reportStatement.opening', '100.00')
            ->where('reportStatement.credit', '150.00')
            ->where('reportStatement.debit', '0.00')
            ->where('reportStatement.closing', '-50.00')
            ->where('detailTable.rows.0.balance', '-50.00')
            ->where('detailTable.rows.0.credit', '150.00')
            ->where('printData.rows.1.cells.الرصيد', '-50.00')
            ->where('printData.rows.1.cells.له (دائن)', '150.00')
            ->where('printData.rows.2.cells.الرصيد', '-50.00')
            ->where('printData.rows.2.cells.له (دائن)', '150.00'));
    }

    public function test_report_accounts_require_subscription_access_and_respect_selected_branches(): void
    {
        $preparer = $this->preparer();
        $this->actingAs($preparer)->get(route('reports.index', ['section' => 'statement', 'subscription' => $this->subscription->id]))->assertForbidden();
        $foreign = Subscription::factory()->create(['branch_id' => $this->south->id]);
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->get(route('reports.index', ['section' => 'statement', 'branch' => $this->north->id, 'subscription' => $foreign->id]))->assertNotFound();
        $this->get(route('reports.index', ['section' => 'statement', 'branch' => $this->north->id, 'search' => $foreign->account_number]))->assertInertia(fn ($page) => $page
            ->where('reportAccounts.total', 0));
    }

    public function test_box_report_uses_recorded_shekel_amounts_and_excludes_later_entries(): void
    {
        $payment = $this->at('2026-09-30 09:00', fn () => $this->pay('12.50', ['currency' => 'USD', 'exchange_rate' => '4.00']));
        $box = $payment->boxEntry->box;
        $this->at('2026-10-01 09:00', fn () => $this->pay('99', ['currency' => 'USD', 'exchange_rate' => '4.00']));

        $this->actingAs($this->preparer())->get(route('reports.index', ['section' => 'boxes', 'from' => '2026-09-30', 'to' => '2026-09-30']))->assertOk()->assertInertia(fn ($page) => $page
            ->has('detailTable.rows', 1)
            ->where('detailTable.rows.0.currency', 'ILS')
            ->where('detailTable.rows.0.amount', '50.00')
            ->where('detailTable.rows.0.count', 1)
            ->where('detailTable.rows.0.number', $box->number));
        $this->get(route('reports.index', ['section' => 'collections', 'from' => '2026-09-30', 'to' => '2026-09-30']))->assertInertia(fn ($page) => $page
            ->where('collections.currencies.0.currency', 'USD')
            ->where('collections.currencies.0.amount', '12.50')
            ->where('collections.currencies.0.total', '50.00'));
    }

    public function test_box_report_lists_only_boxes_open_during_the_period(): void
    {
        $earlier = $this->at('2026-09-20 09:00', fn () => $this->pay('30'))->boxEntry->box;
        $earlier->update(['status' => 'received', 'closed_at' => Carbon::parse('2026-09-21 18:00', 'Asia/Gaza'), 'received_at' => Carbon::parse('2026-09-22 09:00', 'Asia/Gaza')]);
        $other = User::factory()->collector()->create(['branch_id' => $this->north->id]);
        $current = $this->at('2026-09-29 09:00', fn () => SubscriptionTransaction::recordPayment($this->subscription, $other, ['amount' => '20', 'currency' => 'ILS', 'payment_method' => 'cash']))->boxEntry->box;

        $this->actingAs($this->preparer())->get(route('reports.index', ['section' => 'boxes', 'from' => '2026-09-30', 'to' => '2026-09-30']))->assertInertia(fn ($page) => $page
            ->has('detailTable.rows', 1)
            ->where('detailTable.rows.0.boxId', $current->id)
            ->where('detailTable.rows.0.status', 'مفتوح'));
        $this->get(route('reports.index', ['section' => 'boxes', 'from' => '2026-09-20', 'to' => '2026-09-30']))->assertInertia(fn ($page) => $page
            ->has('detailTable.rows', 2)
            ->where('boxData.byStatus', [
                ['status' => 'مفتوح', 'boxes' => 1, 'currencies' => [['currency' => 'ILS', 'amount' => '20.00']]],
                ['status' => 'مغلق', 'boxes' => 0, 'currencies' => []],
                ['status' => 'مستلم', 'boxes' => 1, 'currencies' => [['currency' => 'ILS', 'amount' => '30.00']]],
            ]));
    }

    public function test_detail_sections_skip_the_period_summary_that_only_summary_sections_show(): void
    {
        $this->at('2026-10-01 08:00', fn () => $this->pay('40'));
        $this->actingAs(User::factory()->superAdmin()->create());

        foreach (['boxes', 'debts', 'statement'] as $section) {
            $this->get(route('reports.index', ['section' => $section]))->assertOk()->assertInertia(fn ($page) => $page
                ->where('flow', null)
                ->where('collections', null)
                ->where('comparison', null)
                ->where('transactions', null)
                ->where('readings', null)
                ->where('days', [])
                ->where('branchSummary', []));
        }
        $this->get(route('reports.index', ['section' => 'movements']))->assertInertia(fn ($page) => $page
            ->where('collections.total', '40.00')
            ->has('transactions.data', 1)
            ->has('branchSummary', Branch::count()));
    }

    public function test_statement_filters_preserve_historical_balances_and_print_only_matching_lines(): void
    {
        $this->at('2026-10-01 08:00', fn () => SubscriptionTransaction::recordCharge($this->subscription, $this->collector, ChargeType::Penalty, '100', null));
        $this->at('2026-10-01 09:00', fn () => $this->pay('150'));
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get(route('reports.index', ['section' => 'statement', 'subscription' => $this->subscription->id, 'filter' => ['side' => 'credit'], 'print' => 1]))->assertInertia(fn ($page) => $page
            ->where('reportStatement.closing', '-50.00')
            ->where('detailTable.unfilteredTotal', 2)
            ->where('detailTable.total', 1)
            ->where('detailTable.rows.0.credit', '150.00')
            ->where('detailTable.rows.0.balance', '-50.00')
            ->where('detailTable.totals.credit', '150.00')
            ->where('detailTable.totals.debit', '0.00')
            ->has('printData.rows', 2)
            ->where('printData.rows.0.cells.الرصيد', '-50.00')
            ->where('printData.rows.1.cells.له (دائن)', '150.00'));
    }

    public function test_statement_totals_leave_out_payments_cancelled_within_the_period(): void
    {
        $this->at('2026-09-29 08:00', fn () => SubscriptionTransaction::recordCharge($this->subscription, $this->collector, ChargeType::Penalty, '100', null));
        $this->at('2026-09-29 09:00', fn () => $this->pay('40'));
        $mistake = $this->at('2026-09-29 10:00', fn () => $this->pay('15'));
        $this->at('2026-09-29 10:05', fn () => $mistake->cancel($this->collector, CorrectionReason::Duplicate, null));
        $later = $this->at('2026-09-30 09:00', fn () => $this->pay('10'));
        $this->at('2026-10-01 09:00', fn () => $later->cancel($this->collector, CorrectionReason::NotReceived, null));
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get(route('reports.index', ['section' => 'statement', 'subscription' => $this->subscription->id, 'from' => '2026-09-29', 'to' => '2026-09-30']))->assertInertia(fn ($page) => $page
            ->where('reportStatement.debit', '100.00')
            ->where('reportStatement.credit', '50.00')
            ->where('reportStatement.closing', '50.00')
            ->where('detailTable.total', 5)
            ->where('detailTable.totals.debit', '100.00')
            ->where('detailTable.totals.credit', '50.00'));
    }

    public function test_statement_without_period_movements_keeps_its_opening_balance_and_has_no_fake_table_rows(): void
    {
        $this->at('2026-09-30 09:00', fn () => SubscriptionTransaction::recordCharge($this->subscription, $this->collector, ChargeType::Penalty, '7178', null));
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get(route('reports.index', ['section' => 'statement', 'subscription' => $this->subscription->id]))->assertInertia(fn ($page) => $page
            ->where('reportStatement.opening', '7178.00')
            ->where('reportStatement.closing', '7178.00')
            ->where('detailTable.total', 0)
            ->where('detailTable.active', false)
            ->where('detailTable.rows', []));
    }

    public function test_detail_tables_search_and_sort_debts_with_matching_print_totals(): void
    {
        $a = Subscription::factory()->create(['branch_id' => $this->north->id, 'full_name' => 'أحمد علي']);
        $b = Subscription::factory()->create(['branch_id' => $this->north->id, 'full_name' => 'احمد محمود']);
        $this->at('2026-10-01 08:00', fn () => SubscriptionTransaction::recordCharge($a, $this->collector, ChargeType::Penalty, '20', null));
        $this->at('2026-10-01 08:30', fn () => SubscriptionTransaction::recordCharge($b, $this->collector, ChargeType::Penalty, '100', null));
        $this->actingAs($this->preparer());

        $this->get(route('reports.index', ['section' => 'debts', 'search' => 'احمد', 'sort' => 'balance', 'direction' => 'asc', 'print' => 1]))->assertInertia(fn ($page) => $page
            ->where('detailTable.total', 2)
            ->where('detailTable.rows.0.balance', '20.00')
            ->where('detailTable.rows.1.balance', '100.00')
            ->where('detailTable.totals.balance', '120.00')
            ->where('printData.rows.2.cells.الرصيد عليه', '120.00'));
        $this->get(route('reports.index', ['section' => 'debts', 'search' => ['invalid']]))->assertSessionHasErrors('search');
    }

    public function test_detail_tables_send_one_page_and_load_every_matching_row_only_for_export(): void
    {
        $debtors = Subscription::factory()->count(17)->create(['branch_id' => $this->north->id, 'circuit_breaker_id' => $this->subscription->circuit_breaker_id, 'meter_box_id' => $this->subscription->meter_box_id, 'registered_by' => $this->collector->id]);
        $debtors->each(fn (Subscription $debtor, int $index) => $this->at('2026-10-01 08:00', fn () => SubscriptionTransaction::recordCharge($debtor, $this->collector, ChargeType::Penalty, (string) (10 + $index), null)));
        $this->actingAs($this->preparer());

        $this->get(route('reports.index', ['section' => 'debts', 'per_page' => 15, 'page' => 2]))->assertInertia(fn ($page) => $page
            ->has('detailTable.rows', 2)
            ->where('detailTable.total', 17)
            ->where('detailTable.meta', ['current_page' => 2, 'last_page' => 2, 'from' => 16, 'to' => 17, 'total' => 17])
            ->where('detailTable.totals.balance', '306.00')
            ->missing('debts.rows')
            ->missing('detailExport')
            ->reloadOnly('detailExport', fn ($reload) => $reload->has('detailExport', 17)));
        $this->get(route('reports.index', ['section' => 'debts', 'per_page' => 15, 'page' => 9]))->assertInertia(fn ($page) => $page
            ->where('detailTable.meta.current_page', 2));
    }

    public function test_account_directory_lists_and_searches_all_readable_subscribers_beyond_the_first_page(): void
    {
        $accounts = Subscription::factory()->count(31)->create(['branch_id' => $this->north->id, 'circuit_breaker_id' => $this->subscription->circuit_breaker_id, 'meter_box_id' => $this->subscription->meter_box_id, 'registered_by' => $this->collector->id]);
        $last = $accounts->last();
        $foreign = Subscription::factory()->create(['branch_id' => $this->south->id]);
        $this->actingAs(User::factory()->superAdmin()->create());
        $scope = ['section' => 'statement', 'branch' => $this->north->id];

        $this->get(route('reports.index', $scope))->assertInertia(fn ($page) => $page
            ->where('reportStatement', null)
            ->where('reportAccounts.total', 32)
            ->has('reportAccounts.data', 15));
        $this->get(route('reports.index', [...$scope, 'page' => 3]))->assertInertia(fn ($page) => $page
            ->where('reportAccounts.current_page', 3)
            ->has('reportAccounts.data', 2)
            ->where('reportAccounts.data.1.id', $last->id));
        $this->get(route('reports.index', [...$scope, 'search' => $last->account_number]))->assertInertia(fn ($page) => $page
            ->where('reportAccounts.total', 1)
            ->where('reportAccounts.data.0.id', $last->id));
        $this->get(route('reports.index', [...$scope, 'search' => $foreign->account_number]))->assertInertia(fn ($page) => $page->where('reportAccounts.total', 0));
    }

    public function test_account_directory_requires_subscriber_permission_and_hides_unreadable_archived_accounts(): void
    {
        $actor = $this->preparer();
        $this->actingAs($actor)->get(route('reports.index', ['section' => 'statement']))->assertInertia(fn ($page) => $page->where('reportAccounts', null));
        $actor->permissions()->sync(Permission::idsFor([PermissionKey::ViewFinancialReports, PermissionKey::ViewSubscriptions]));
        $actor->unsetRelation('permissions');
        $archived = Subscription::factory()->create(['branch_id' => $this->north->id, 'archived_at' => now()]);

        $this->get(route('reports.index', ['section' => 'statement']))->assertInertia(fn ($page) => $page
            ->where('reportAccounts.total', 1)
            ->where('reportAccounts.data.0.id', $this->subscription->id));
        $this->get(route('reports.index', ['section' => 'statement', 'search' => $archived->account_number]))->assertInertia(fn ($page) => $page
            ->where('reportAccounts.total', 0));
    }

    /**
     * @param  array<string, string>  $details
     */
    private function pay(string $amount, array $details = [], ?Subscription $subscription = null): SubscriptionTransaction
    {
        return SubscriptionTransaction::recordPayment($subscription ?? $this->subscription, $this->collector, [
            'amount' => $amount, 'currency' => 'ILS', 'payment_method' => 'cash', ...$details,
        ]);
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $record
     * @return TResult
     */
    private function at(string $moment, callable $record): mixed
    {
        $this->travelTo(Carbon::parse($moment, 'Asia/Gaza'));
        $result = $record();
        $this->travelTo(Carbon::parse('2026-10-01 10:00', 'Asia/Gaza'));

        return $result;
    }
}
