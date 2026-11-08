<?php

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\DiscountMethod;
use App\Enums\PermissionKey;
use App\Models\BillingPeriod;
use App\Models\Branch;
use App\Models\MeterReading;
use App\Models\Permission;
use App\Models\SubscriberPeriodBalance;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BillingPeriods;
use App\Support\PeriodStatement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * How each period's payments, discounts and advance settle the invoice
 * (with its charges) and the accumulated debt, and what becomes the new
 * advance: the six worked examples, the edge cases around them, and the
 * report, its filters and the dashboard reading the same figures.
 */
class PaymentAllocationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00', config('app.business_timezone')));
        $this->branch = Branch::factory()->create();
        $this->manager = User::factory()->branchAdmin()->for($this->branch)->create();
        $this->actingAs($this->manager);
    }

    private function openPeriod(string $start, string $end): BillingPeriod
    {
        app(BillingPeriods::class)->forget();

        return app(BillingPeriods::class)->create($this->branch, CarbonImmutable::parse($start), CarbonImmutable::parse($end), $this->manager);
    }

    private function subscription(array $attributes = []): Subscription
    {
        return Subscription::factory()->for($this->branch)->create(['kilowatt_price' => '1.00', 'minimum_charge' => '0.00', 'initial_reading' => 0, ...$attributes]);
    }

    /** The period's invoice, as a weekly reading approved in it: kilowatts at 1 shekel each. */
    private function invoice(Subscription $subscription, float $kilowatts): void
    {
        app(BillingPeriods::class)->forget();
        $subscription->refresh()->load(['tariff', 'circuitBreaker', 'standingDiscount']);
        $weekStart = MeterReading::latestEndedWeekStart($subscription->branch_id);
        $reading = MeterReading::recordFor($subscription, $weekStart, $subscription->previousReadingBefore($weekStart) + $kilowatts, $this->manager, null);
        $this->assertTrue($reading->approve($this->manager, confirmUnusual: true));
    }

    private function pay(Subscription $subscription, string $amount, string $method = 'cash'): void
    {
        app(BillingPeriods::class)->forget();
        $bank = $method === 'cash' ? [] : ['bank_name' => 'بنك فلسطين', 'sender_name' => 'Ahmad', 'reference_number' => 'TR-'.$amount];
        SubscriptionTransaction::recordPayment($subscription, $this->manager, ['amount' => $amount, 'currency' => 'ILS', 'payment_method' => $method, ...$bank]);
    }

    /** A first period that leaves the subscription owing `$accumulated` or `$advance` ahead, and the period after it, open. */
    private function carried(Subscription $subscription, int $accumulated = 0, int $advance = 0): BillingPeriod
    {
        $this->openPeriod('2026-10-01', '2026-10-07');
        if ($accumulated > 0) {
            $this->invoice($subscription, $accumulated);
        }
        if ($advance > 0) {
            $this->pay($subscription, (string) $advance);
        }

        return $this->openPeriod('2026-10-08', '2026-10-14');
    }

    private function row(BillingPeriod $period, Subscription $subscription): array
    {
        app(BillingPeriods::class)->forget();

        return app(PeriodStatement::class)->rows($period->fresh())->get($subscription->id);
    }

    /**
     * The settlement of a row in whole shekels, keyed as the request names
     * them, with the invoice and accumulated rates.
     *
     * @return array<string, int|null>
     */
    private function settled(array $row): array
    {
        $rates = PeriodStatement::rates($row);

        return [
            'payments' => intdiv($row['payments'], 100),
            'invoiceTarget' => intdiv($row['invoiceTarget'], 100),
            'invoiceSettled' => intdiv($row['invoiceSettled'], 100),
            'paidToAccumulated' => intdiv($row['paidToAccumulated'], 100),
            'newAdvance' => intdiv($row['newAdvance'], 100),
            'invoiceRemaining' => intdiv($row['invoiceRemaining'], 100),
            'accumulatedRemaining' => intdiv($row['accumulatedRemaining'], 100),
            'remaining' => intdiv($row['remaining'], 100),
            'invoiceRate' => $rates['invoiceRate'],
            'accumulatedRate' => $rates['accumulatedRate'],
        ];
    }

    private function grantReports(PermissionKey ...$keys): void
    {
        $this->manager->permissions()->syncWithoutDetaching(Permission::idsFor($keys));
        $this->manager->unsetRelation('permissions');
    }

    public function test_example_1_the_payment_settles_the_invoice_and_the_accumulated_in_full(): void
    {
        $subscription = $this->subscription();
        $period = $this->carried($subscription, accumulated: 20);
        $this->invoice($subscription, 100);
        $this->pay($subscription, '120');

        $this->assertSame([
            'payments' => 120, 'invoiceTarget' => 100, 'invoiceSettled' => 100, 'paidToAccumulated' => 20, 'newAdvance' => 0,
            'invoiceRemaining' => 0, 'accumulatedRemaining' => 0, 'remaining' => 0, 'invoiceRate' => 100, 'accumulatedRate' => 100,
        ], $this->settled($this->row($period, $subscription)));
    }

    public function test_example_2_paying_over_the_invoice_with_nothing_accumulated_leaves_a_new_advance(): void
    {
        $subscription = $this->subscription();
        $period = $this->openPeriod('2026-10-08', '2026-10-14');
        $this->invoice($subscription, 100);
        $this->pay($subscription, '120');

        $this->assertSame([
            'payments' => 120, 'invoiceTarget' => 100, 'invoiceSettled' => 100, 'paidToAccumulated' => 0, 'newAdvance' => 20,
            'invoiceRemaining' => 0, 'accumulatedRemaining' => 0, 'remaining' => -20, 'invoiceRate' => 100, 'accumulatedRate' => null,
        ], $this->settled($this->row($period, $subscription)));
    }

    public function test_example_3_the_advance_brought_forward_and_a_new_payment_settle_the_invoice(): void
    {
        $subscription = $this->subscription();
        $period = $this->carried($subscription, advance: 50);
        $this->invoice($subscription, 100);
        $this->pay($subscription, '50');

        $row = $this->row($period, $subscription);
        $this->assertSame([
            'payments' => 50, 'invoiceTarget' => 100, 'invoiceSettled' => 100, 'paidToAccumulated' => 0, 'newAdvance' => 0,
            'invoiceRemaining' => 0, 'accumulatedRemaining' => 0, 'remaining' => 0, 'invoiceRate' => 100, 'accumulatedRate' => null,
        ], $this->settled($row));
        $this->assertSame([5000, 5000], [$row['advanceUsed'], $row['paidToInvoice']]);
    }

    public function test_example_4_the_advance_brought_forward_and_a_larger_payment_leave_a_new_advance(): void
    {
        $subscription = $this->subscription();
        $period = $this->carried($subscription, advance: 50);
        $this->invoice($subscription, 100);
        $this->pay($subscription, '70');

        $row = $this->row($period, $subscription);
        $this->assertSame([
            'payments' => 70, 'invoiceTarget' => 100, 'invoiceSettled' => 100, 'paidToAccumulated' => 0, 'newAdvance' => 20,
            'invoiceRemaining' => 0, 'accumulatedRemaining' => 0, 'remaining' => -20, 'invoiceRate' => 100, 'accumulatedRate' => null,
        ], $this->settled($row));
        $this->assertSame([5000, 5000], [$row['advanceUsed'], $row['paidToInvoice']]);
    }

    public function test_example_5_a_discount_settles_the_invoice_without_counting_as_money_or_reaching_the_accumulated(): void
    {
        $subscription = $this->subscription();
        $period = $this->carried($subscription, accumulated: 20);
        $this->invoice($subscription, 100);
        SubscriptionTransaction::recordDiscount($subscription, $this->manager, DiscountMethod::Shekel, '90', 'خصم');
        $this->pay($subscription, '30');

        $row = $this->row($period, $subscription);
        $this->assertSame([
            'payments' => 30, 'invoiceTarget' => 100, 'invoiceSettled' => 100, 'paidToAccumulated' => 0, 'newAdvance' => 0,
            'invoiceRemaining' => 0, 'accumulatedRemaining' => 0, 'remaining' => 0, 'invoiceRate' => 100, 'accumulatedRate' => 0,
        ], $this->settled($row));
        // The payment and the discount stay apart: 30 of money and 70 of the discount on the invoice, the rest of the discount on the debt.
        $this->assertSame([9000, 3000, 7000, 2000], [$row['discounts'], $row['paidToInvoice'], $row['discountToInvoice'], $row['discountToAccumulated']]);
    }

    public function test_example_6_charges_are_part_of_the_invoice_target_never_the_accumulated(): void
    {
        $subscription = $this->subscription();
        $period = $this->openPeriod('2026-10-08', '2026-10-14');
        $this->invoice($subscription, 10000);
        SubscriptionTransaction::recordCharge($subscription, $this->manager, ChargeType::Penalty, '1200', 'رسم');
        $this->pay($subscription, '11200');

        $row = $this->row($period, $subscription);
        $this->assertSame([
            'payments' => 11200, 'invoiceTarget' => 11200, 'invoiceSettled' => 11200, 'paidToAccumulated' => 0, 'newAdvance' => 0,
            'invoiceRemaining' => 0, 'accumulatedRemaining' => 0, 'remaining' => 0, 'invoiceRate' => 100, 'accumulatedRate' => null,
        ], $this->settled($row));
        $this->assertSame(0, $row['accumulated']);
    }

    public function test_a_subscription_with_nothing_owed_or_ahead_has_nothing_to_collect(): void
    {
        $subscription = $this->subscription();
        $period = $this->openPeriod('2026-10-08', '2026-10-14');

        $row = $this->row($period, $subscription);
        $this->assertSame(0, $row['collectible']);
        $this->assertSame(['totalRate' => null, 'invoiceRate' => null, 'accumulatedRate' => null], PeriodStatement::rates($row));
        $this->assertSame(0, array_sum(array_map(fn (string $column): int => abs($row[$column]), PeriodStatement::SETTLEMENT)));
    }

    public function test_only_accumulated_is_collected_in_part(): void
    {
        $subscription = $this->subscription();
        $period = $this->carried($subscription, accumulated: 50);
        $this->pay($subscription, '20');

        $row = $this->row($period, $subscription);
        $this->assertSame(['payments' => 20, 'invoiceTarget' => 0, 'invoiceSettled' => 0, 'paidToAccumulated' => 20, 'newAdvance' => 0,
            'invoiceRemaining' => 0, 'accumulatedRemaining' => 30, 'remaining' => 30, 'invoiceRate' => null, 'accumulatedRate' => 40], $this->settled($row));
        $this->assertSame(40, PeriodStatement::rates($row)['totalRate']);
    }

    public function test_only_an_advance_is_kept_for_the_next_invoice(): void
    {
        $subscription = $this->subscription();
        $period = $this->carried($subscription, advance: 30);

        $row = $this->row($period, $subscription);
        $this->assertSame([3000, 0, 0, 0, -3000], [$row['advance'], $row['advanceUsed'], $row['newAdvance'], $row['collectible'], $row['remaining']]);
    }

    public function test_a_discount_and_charges_together_with_a_partial_payment(): void
    {
        $subscription = $this->subscription();
        $period = $this->openPeriod('2026-10-08', '2026-10-14');
        $this->invoice($subscription, 100);
        SubscriptionTransaction::recordCharge($subscription, $this->manager, ChargeType::Penalty, '30', 'رسم');
        SubscriptionTransaction::recordDiscount($subscription, $this->manager, DiscountMethod::Shekel, '20', null);
        $this->pay($subscription, '50');

        $row = $this->row($period, $subscription);
        $this->assertSame([13000, 5000, 2000, 7000, 6000, 6000], [$row['invoiceTarget'], $row['paidToInvoice'], $row['discountToInvoice'], $row['invoiceSettled'], $row['invoiceRemaining'], $row['remaining']]);
        $this->assertSame(['totalRate' => 45, 'invoiceRate' => 53, 'accumulatedRate' => null], PeriodStatement::rates($row));
    }

    public function test_a_partial_payment_leaves_part_of_the_invoice(): void
    {
        $subscription = $this->subscription();
        $period = $this->openPeriod('2026-10-08', '2026-10-14');
        $this->invoice($subscription, 100);
        $this->pay($subscription, '40');

        $this->assertSame(['payments' => 40, 'invoiceTarget' => 100, 'invoiceSettled' => 40, 'paidToAccumulated' => 0, 'newAdvance' => 0,
            'invoiceRemaining' => 60, 'accumulatedRemaining' => 0, 'remaining' => 60, 'invoiceRate' => 40, 'accumulatedRate' => null], $this->settled($this->row($period, $subscription)));
    }

    public function test_a_payment_settles_the_invoice_first_then_part_of_the_accumulated(): void
    {
        $subscription = $this->subscription();
        $period = $this->carried($subscription, accumulated: 50);
        $this->invoice($subscription, 100);
        $this->pay($subscription, '130');

        $row = $this->row($period, $subscription);
        $this->assertSame(['payments' => 130, 'invoiceTarget' => 100, 'invoiceSettled' => 100, 'paidToAccumulated' => 30, 'newAdvance' => 0,
            'invoiceRemaining' => 0, 'accumulatedRemaining' => 20, 'remaining' => 20, 'invoiceRate' => 100, 'accumulatedRate' => 60], $this->settled($row));
        $this->assertSame(86, PeriodStatement::rates($row)['totalRate']);
    }

    public function test_paying_over_everything_owed_carries_a_new_advance_into_the_next_period(): void
    {
        $subscription = $this->subscription();
        $period = $this->carried($subscription, accumulated: 20);
        $this->invoice($subscription, 100);
        $this->pay($subscription, '150');

        $row = $this->row($period, $subscription);
        $this->assertSame(['payments' => 150, 'invoiceTarget' => 100, 'invoiceSettled' => 100, 'paidToAccumulated' => 20, 'newAdvance' => 30,
            'invoiceRemaining' => 0, 'accumulatedRemaining' => 0, 'remaining' => -30, 'invoiceRate' => 100, 'accumulatedRate' => 100], $this->settled($row));
        $this->assertSame(100, PeriodStatement::rates($row)['totalRate']);

        $next = $this->openPeriod('2026-10-15', '2026-10-21');
        $this->assertSame([3000, 0], [$this->row($next, $subscription)['advance'], $this->row($next, $subscription)['payments']]);
    }

    public function test_several_payments_by_different_methods_are_split_in_the_order_they_were_made(): void
    {
        $this->grantReports(PermissionKey::ViewFinancialReports);
        $subscription = $this->subscription();
        $period = $this->carried($subscription, accumulated: 20);
        $this->invoice($subscription, 100);
        $this->pay($subscription, '50');
        $this->travel(1)->minutes();
        $this->pay($subscription, '40', 'bank_transfer');
        $this->travel(1)->minutes();
        app(BillingPeriods::class)->forget();
        SubscriptionTransaction::recordClearing($subscription, $this->manager, '40', 'خدمة');

        $row = $this->row($period, $subscription);
        $this->assertSame([13000, 10000, 2000, 1000], [$row['payments'], $row['paidToInvoice'], $row['paidToAccumulated'], $row['newAdvance']]);

        $detail = $this->getJson(route('reports.remaining.payments', $subscription))->assertOk()->json();
        $this->assertSame($period->label(), $detail['period']['label']);
        $this->assertSame(
            [['50.00', '50.00', '0.00', '0.00'], ['40.00', '40.00', '0.00', '0.00'], ['40.00', '10.00', '20.00', '10.00']],
            array_map(fn (array $payment): array => [$payment['amount'], $payment['toInvoice'], $payment['toAccumulated'], $payment['toAdvance']], $detail['payments']),
        );
        $this->assertSame('مقاصة', $detail['payments'][2]['method']);
        $this->assertNotSame($detail['payments'][0]['method'], $detail['payments'][1]['method']);
    }

    public function test_reading_again_and_approving_never_count_a_payment_twice(): void
    {
        $subscription = $this->subscription();
        $first = $this->carried($subscription, accumulated: 20);
        $this->invoice($subscription, 100);
        $this->pay($subscription, '150');

        $once = $this->row($first, $subscription);
        $this->assertSame($once, $this->row($first, $subscription));

        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00', config('app.business_timezone')));
        $next = $this->openPeriod('2026-10-15', '2026-10-21');
        app(BillingPeriods::class)->approve($first->fresh(), $this->manager);
        $kept = SubscriberPeriodBalance::query()->where('billing_period_id', $first->id)->where('subscription_id', $subscription->id)->sole();
        $this->assertSame(['100.00', '100.00', '100.00', '20.00', '30.00'], [$kept->invoice_target, $kept->paid_to_invoice, $kept->invoice_settled, $kept->paid_to_accumulated, $kept->new_advance]);
        $this->assertSame($once, $this->row($first, $subscription));

        try {
            app(BillingPeriods::class)->approve($first->fresh(), $this->manager);
            $this->fail('A period was approved twice.');
        } catch (ValidationException) {
            $this->assertSame(1, SubscriberPeriodBalance::query()->where('billing_period_id', $first->id)->count());
        }

        // The approved period's new advance is the next one's advance brought forward, and its payments stay in it.
        $carried = $this->row($next, $subscription);
        $this->assertSame([3000, 0, 0], [$carried['advance'], $carried['payments'], $carried['paidToAccumulated']]);
    }

    public function test_the_previous_periods_payments_are_not_collected_again(): void
    {
        $subscription = $this->subscription();
        $this->openPeriod('2026-10-01', '2026-10-07');
        $this->invoice($subscription, 100);
        $this->pay($subscription, '60');
        $period = $this->openPeriod('2026-10-08', '2026-10-14');
        $this->invoice($subscription, 30);

        $row = $this->row($period, $subscription);
        $this->assertSame([4000, 0, 0, 0, 7000], [$row['accumulated'], $row['payments'], $row['paidToInvoice'], $row['paidToAccumulated'], $row['remaining']]);
    }

    public function test_the_report_totals_follow_the_search_status_and_branch_filters(): void
    {
        $this->grantReports(PermissionKey::ViewFinancialReports, PermissionKey::ViewAllFinancialReports);
        $paying = $this->subscription(['full_name' => 'سامر الدافع']);
        $owing = $this->subscription(['full_name' => 'خالد المدين']);
        $other = Branch::factory()->create();
        $elsewhere = Subscription::factory()->for($other)->create(['kilowatt_price' => '1.00', 'minimum_charge' => '0.00', 'initial_reading' => 0]);
        $period = $this->carried($owing, accumulated: 40);
        $this->invoice($paying, 100);
        $this->pay($paying, '120');
        $this->invoice($owing, 50);
        $this->pay($owing, '60');
        $owing->update(['status' => 'disconnected']);
        app(BillingPeriods::class)->forget();
        app(BillingPeriods::class)->create($other, CarbonImmutable::parse('2026-10-08'), CarbonImmutable::parse('2026-10-14'), $this->manager);
        $this->pay($elsewhere, '25');

        $all = $this->get(route('reports.remaining'))->assertOk()->inertiaProps('summary');
        $this->assertSame(['205.00', '150.00', '10.00', '45.00', 2], [$all['payments'], $all['invoiceSettled'], $all['paidToAccumulated'], $all['newAdvance'], $all['ahead']]);

        $branch = $this->get(route('reports.remaining', ['branch_id' => $this->branch->id]))->inertiaProps('summary');
        $this->assertSame(['180.00', '150.00', '10.00', '20.00', 25], [$branch['payments'], $branch['invoiceSettled'], $branch['paidToAccumulated'], $branch['newAdvance'], $branch['accumulatedRate']]);

        $searched = $this->get(route('reports.remaining', ['branch_id' => $this->branch->id, 'search' => 'سامر']))->inertiaProps('summary');
        $row = $this->row($period, $paying);
        $this->assertSame(PeriodStatement::present([...PeriodStatement::summarize([$row])]), $searched);

        $disconnected = $this->get(route('reports.remaining', ['branch_id' => $this->branch->id, 'status' => 'disconnected']))->inertiaProps('summary');
        $this->assertSame(['60.00', '50.00', '10.00', '0.00', 1], [$disconnected['payments'], $disconnected['invoiceSettled'], $disconnected['paidToAccumulated'], $disconnected['newAdvance'], $disconnected['count']]);
    }

    public function test_the_dashboard_shows_the_same_collection_as_the_report(): void
    {
        $this->grantReports(PermissionKey::ViewFinancialReports);
        $subscription = $this->subscription();
        $this->carried($subscription, accumulated: 50);
        $this->invoice($subscription, 100);
        $this->pay($subscription, '130');

        $summary = $this->get(route('reports.remaining'))->assertOk()->inertiaProps('summary');
        $accounts = $this->get(route('dashboard'))->assertOk()->inertiaProps('periodAccounts');

        foreach (['payments', 'invoiceSettled', 'paidToAccumulated', 'newAdvance', 'totalRate', 'invoiceRate', 'accumulatedRate'] as $key) {
            $this->assertSame($summary[$key], $accounts['totals'][$key], $key);
        }
        $this->assertSame(['total' => 86, 'invoice' => 100, 'accumulated' => 60], $accounts['rates']);
    }

    public function test_the_payment_split_is_only_for_subscriptions_the_reader_may_see(): void
    {
        $subscription = $this->subscription();
        $this->openPeriod('2026-10-08', '2026-10-14');
        $collector = User::factory()->collector()->for($this->branch)->create();
        $this->actingAs($collector)->getJson(route('reports.remaining.payments', $subscription))->assertForbidden();

        $this->actingAs($this->manager);
        $this->grantReports(PermissionKey::ViewFinancialReports);
        $elsewhere = Subscription::factory()->for(Branch::factory())->create();
        $this->assertContains($this->getJson(route('reports.remaining.payments', $elsewhere))->status(), [403, 404]);
        $this->getJson(route('reports.remaining.payments', $subscription))->assertOk()->assertJsonPath('payments', []);
    }
}
