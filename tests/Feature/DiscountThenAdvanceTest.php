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
use Tests\TestCase;

/**
 * The invoice target: the invoice and its charges, less the discount first,
 * then less the advance brought forward (never more than is left after the
 * discount, never below nothing); the unused advance is carried on. The
 * invoice payments are new money only, and the invoice rate is them over
 * the target.
 */
class DiscountThenAdvanceTest extends TestCase
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
        $periods = app(BillingPeriods::class);
        $periods->forget();
        if ($open = $periods->open($this->branch->id)) {
            $periods->close($open, $this->manager);
        }

        return $periods->create($this->branch, CarbonImmutable::parse($start), CarbonImmutable::parse($end), $this->manager);
    }

    private function subscription(): Subscription
    {
        return Subscription::factory()->for($this->branch)->create(['kilowatt_price' => '1.00', 'minimum_charge' => '0.00', 'initial_reading' => 0]);
    }

    private function invoice(Subscription $subscription, float $kilowatts): void
    {
        app(BillingPeriods::class)->forget();
        $subscription->refresh()->load(['tariff', 'circuitBreaker', 'standingDiscount']);
        $weekStart = MeterReading::latestEndedWeekStart($subscription->branch_id);
        $reading = MeterReading::recordFor($subscription, $weekStart, $subscription->previousReadingBefore($weekStart) + $kilowatts, $this->manager, null);
        $this->assertTrue($reading->approve($this->manager, confirmUnusual: true));
    }

    private function pay(Subscription $subscription, string $amount): void
    {
        app(BillingPeriods::class)->forget();
        SubscriptionTransaction::recordPayment($subscription, $this->manager, ['amount' => $amount, 'currency' => 'ILS', 'payment_method' => 'cash']);
    }

    private function row(BillingPeriod $period, Subscription $subscription): array
    {
        app(BillingPeriods::class)->forget();

        return app(PeriodStatement::class)->rows($period->fresh())->get($subscription->id);
    }

    /**
     * A subscription that brings `$advance` forward into an open period
     * whose invoice, charges and discount are as given.
     *
     * @return array{0: Subscription, 1: BillingPeriod}
     */
    private function settle(int $invoice, int $charges, int $discount, int $advance): array
    {
        $subscription = $this->subscription();
        $this->openPeriod('2026-10-01', '2026-10-07');
        if ($advance > 0) {
            $this->pay($subscription, (string) $advance);
        }
        $period = $this->openPeriod('2026-10-08', '2026-10-14');
        $this->invoice($subscription, $invoice);
        if ($charges > 0) {
            SubscriptionTransaction::recordCharge($subscription, $this->manager, ChargeType::Penalty, (string) $charges, 'رسم');
        }
        if ($discount > 0) {
            SubscriptionTransaction::recordDiscount($subscription, $this->manager, DiscountMethod::Shekel, (string) $discount, 'خصم');
        }

        return [$subscription, $period];
    }

    /** @return array<string, int> in shekels */
    private function shekels(array $row, array $keys): array
    {
        return array_combine($keys, array_map(fn (string $key): int => intdiv($row[$key], 100), $keys));
    }

    private const KEYS = ['afterDiscount', 'advanceUsed', 'invoiceTarget', 'unusedAdvance', 'carriedAdvance', 'remaining'];

    public function test_example_1_the_advance_covers_part_of_what_is_left_after_the_discount(): void
    {
        [$subscription, $period] = $this->settle(invoice: 50, charges: 20, discount: 10, advance: 40);

        // 50 + 20 − 10 − 40 = 20
        $this->assertSame(['afterDiscount' => 60, 'advanceUsed' => 40, 'invoiceTarget' => 20, 'unusedAdvance' => 0, 'carriedAdvance' => 0, 'remaining' => 20],
            $this->shekels($this->row($period, $subscription), self::KEYS));
    }

    public function test_example_2_only_what_is_left_after_the_discount_is_taken_from_the_advance_and_the_rest_is_carried(): void
    {
        [$subscription, $period] = $this->settle(invoice: 50, charges: 20, discount: 10, advance: 70);

        $this->assertSame(['afterDiscount' => 60, 'advanceUsed' => 60, 'invoiceTarget' => 0, 'unusedAdvance' => 10, 'carriedAdvance' => 10, 'remaining' => -10],
            $this->shekels($this->row($period, $subscription), self::KEYS));

        $next = $this->openPeriod('2026-10-15', '2026-10-21');
        $this->assertSame(1000, $this->row($next, $subscription)['advance']);
    }

    public function test_example_3_a_large_advance_settles_the_whole_target_and_carries_the_rest(): void
    {
        [$subscription, $period] = $this->settle(invoice: 60, charges: 50, discount: 0, advance: 200);

        $row = $this->row($period, $subscription);
        $this->assertSame(['afterDiscount' => 110, 'advanceUsed' => 110, 'invoiceTarget' => 0, 'unusedAdvance' => 90, 'carriedAdvance' => 90, 'remaining' => -90],
            $this->shekels($row, self::KEYS));
        $this->assertSame(100, PeriodStatement::rates($row)['invoiceRate']);

        $next = $this->openPeriod('2026-10-15', '2026-10-21');
        $this->assertSame(9000, $this->row($next, $subscription)['advance']);
    }

    public function test_the_discount_comes_off_before_the_advance_and_the_target_is_never_below_nothing(): void
    {
        // A discount larger than the invoice leaves nothing for the advance to settle: it all carries on, with the discount's surplus.
        [$subscription, $period] = $this->settle(invoice: 50, charges: 0, discount: 80, advance: 40);

        $row = $this->row($period, $subscription);
        $this->assertSame(['afterDiscount' => 0, 'advanceUsed' => 0, 'invoiceTarget' => 0, 'unusedAdvance' => 40, 'carriedAdvance' => 70, 'remaining' => -70],
            $this->shekels($row, self::KEYS));
        $this->assertSame(5000, $row['discountToInvoice']);
    }

    public function test_the_invoice_payments_are_new_money_only_and_the_rate_is_over_the_target(): void
    {
        [$subscription, $period] = $this->settle(invoice: 50, charges: 20, discount: 10, advance: 40);
        $this->pay($subscription, '10');

        $row = $this->row($period, $subscription);
        // Target 20; the new payment of 10 is the only invoice payment: not the discount (10), not the advance used (40).
        $this->assertSame([2000, 1000, 1000, 1000], [$row['invoiceTarget'], $row['payments'], $row['paidToInvoice'], $row['invoiceRemaining']]);
        $this->assertSame(['totalRate' => 50, 'invoiceRate' => 50, 'accumulatedRate' => null], PeriodStatement::rates($row));
    }

    public function test_the_advance_is_taken_off_once_and_the_balance_is_the_ledgers(): void
    {
        [$subscription, $period] = $this->settle(invoice: 50, charges: 20, discount: 10, advance: 40);
        $this->pay($subscription, '25');

        $row = $this->row($period, $subscription);
        // Owed 20 after the discount and the advance; paid 25: 5 is new advance. The balance is the ledger's, not less the advance again.
        $this->assertSame([2000, 2000, 500, 500, -500], [$row['invoiceTarget'], $row['paidToInvoice'], $row['newAdvance'], $row['carriedAdvance'], $row['remaining']]);
        $this->assertSame(-500, (int) round($subscription->balance() * 100));
        $this->assertSame($row['remaining'], $row['invoiceRemaining'] + $row['accumulatedRemaining'] - $row['carriedAdvance']);
    }

    public function test_closing_and_opening_the_next_period_carries_the_advance_once(): void
    {
        [$subscription, $period] = $this->settle(invoice: 60, charges: 50, discount: 0, advance: 200);
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00', config('app.business_timezone')));
        $next = $this->openPeriod('2026-10-15', '2026-10-21');
        app(BillingPeriods::class)->approve($period->fresh(), $this->manager);

        $kept = SubscriberPeriodBalance::query()->where('billing_period_id', $period->id)->where('subscription_id', $subscription->id)->sole();
        $this->assertSame(['0.00', '110.00', '-90.00'], [$kept->invoice_target, $kept->advance_used, $kept->closing]);

        $this->invoice($subscription, 30);
        $carried = $this->row($next, $subscription);
        // The 90 left is brought forward once: it settles the next 30 and 60 carries on; nothing of the last period is counted again.
        $this->assertSame([9000, 3000, 0, 6000, 0], [$carried['advance'], $carried['advanceUsed'], $carried['invoiceTarget'], $carried['carriedAdvance'], $carried['payments']]);
    }

    public function test_the_parts_always_add_up_to_the_balance(): void
    {
        mt_srand(2026);
        for ($i = 0; $i < 3000; $i++) {
            $row = PeriodStatement::figures(mt_rand(-30000, 30000), mt_rand(-2000, 20000), mt_rand(-3000, 15000), mt_rand(-2000, 8000), mt_rand(-5000, 40000));

            $this->assertSame($row['remaining'], $row['invoiceRemaining'] + $row['accumulatedRemaining'] - $row['carriedAdvance']);
            $this->assertLessThanOrEqual($row['afterDiscount'], $row['advanceUsed']);
            $this->assertGreaterThanOrEqual(0, $row['invoiceTarget']);
            $this->assertSame(max($row['payments'], 0), $row['paidToInvoice'] + $row['paidToAccumulated'] + $row['newAdvance']);
        }
    }

    public function test_the_dashboard_and_the_report_show_the_same_figures(): void
    {
        $this->manager->permissions()->syncWithoutDetaching(Permission::idsFor([PermissionKey::ViewFinancialReports]));
        $this->manager->unsetRelation('permissions');
        [$subscription] = $this->settle(invoice: 50, charges: 20, discount: 10, advance: 40);
        $this->pay($subscription, '10');

        $summary = $this->get(route('reports.remaining'))->assertOk()->inertiaProps('summary');
        $accounts = $this->get(route('dashboard'))->assertOk()->inertiaProps('periodAccounts');

        $this->assertSame(['20.00', '10.00', '50'], [$summary['invoiceTarget'], $summary['paidToInvoice'], (string) $summary['invoiceRate']]);
        foreach (['payments', 'paidToInvoice', 'paidToAccumulated', 'newAdvance', 'invoiceTarget', 'advanceUsed', 'totalRate', 'invoiceRate', 'accumulatedRate'] as $key) {
            $this->assertSame($summary[$key], $accounts['totals'][$key], $key);
        }
    }
}
