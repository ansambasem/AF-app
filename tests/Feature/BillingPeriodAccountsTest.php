<?php

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\CorrectionReason;
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
use App\Support\DailySeries;
use App\Support\PeriodStatement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BillingPeriodAccountsTest extends TestCase
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

    /** Close the open period by hand, then set up the one from `$start` to `$end` (opening it when its first day has come). */
    private function openPeriod(string $start, string $end): BillingPeriod
    {
        $periods = app(BillingPeriods::class);
        $periods->forget();
        // A period stays open until it is closed by hand: the branch's manager closes the open one first.
        if ($open = $periods->open($this->branch->id)) {
            $periods->close($open, $this->manager);
        }

        return $periods->create($this->branch, CarbonImmutable::parse($start), CarbonImmutable::parse($end), $this->manager);
    }

    private function subscription(): Subscription
    {
        return Subscription::factory()->for($this->branch)->create(['kilowatt_price' => '1.00', 'minimum_charge' => '0.00', 'initial_reading' => 0]);
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

    public function test_case_1_an_overpayment_becomes_the_next_periods_advance(): void
    {
        $subscription = $this->subscription();
        $first = $this->openPeriod('2026-10-01', '2026-10-07');
        $this->invoice($subscription, 100);
        $this->pay($subscription, '120');

        $row = $this->row($first, $subscription);
        $this->assertSame([10000, 0, 0, 10000, 12000, -2000], [$row['invoice'], $row['accumulated'], $row['advance'], $row['beforePayments'], $row['payments'], $row['remaining']]);
        $this->assertEquals([0.0, 100.0, 100.0, '1.00'], [$row['previousReading'], $row['currentReading'], $row['consumption'], $row['unitPrice']]);

        $second = $this->openPeriod('2026-10-08', '2026-10-14');
        $next = $this->row($second, $subscription);
        $this->assertSame([0, 2000], [$next['accumulated'], $next['advance']]);
    }

    public function test_case_2_the_advance_is_taken_off_the_next_invoice(): void
    {
        $subscription = $this->subscription();
        $this->openPeriod('2026-10-01', '2026-10-07');
        $this->invoice($subscription, 100);
        $this->pay($subscription, '120');
        $second = $this->openPeriod('2026-10-08', '2026-10-14');
        $this->invoice($subscription, 50);
        $this->pay($subscription, '10');

        $row = $this->row($second, $subscription);
        $this->assertSame([5000, 0, 2000, 3000, 1000, 2000], [$row['invoice'], $row['accumulated'], $row['advance'], $row['net'], $row['payments'], $row['remaining']]);
    }

    public function test_case_3_accumulated_discount_and_charge_add_up(): void
    {
        $subscription = $this->subscription();
        $this->openPeriod('2026-10-01', '2026-10-07');
        $this->invoice($subscription, 20);
        $third = $this->openPeriod('2026-10-08', '2026-10-14');
        $this->invoice($subscription, 70);
        SubscriptionTransaction::recordDiscount($subscription, $this->manager, DiscountMethod::Shekel, '40', 'سبب الخصم');
        SubscriptionTransaction::recordCharge($subscription, $this->manager, ChargeType::Penalty, '30', 'تركيب طبلون');

        $row = $this->row($third, $subscription);
        $this->assertSame([7000, 2000, 0, 9000, 4000, 3000, 8000, 0, 8000], [
            $row['invoice'], $row['accumulated'], $row['advance'], $row['net'], $row['discounts'], $row['charges'], $row['beforePayments'], $row['payments'], $row['remaining'],
        ]);
    }

    public function test_case_4_invoice_discount_charge_and_payment_in_one_period(): void
    {
        $subscription = $this->subscription();
        $period = $this->openPeriod('2026-10-08', '2026-10-14');
        $this->invoice($subscription, 100);
        SubscriptionTransaction::recordDiscount($subscription, $this->manager, DiscountMethod::Shekel, '20', null);
        SubscriptionTransaction::recordCharge($subscription, $this->manager, ChargeType::Penalty, '10', 'رسم');
        $this->pay($subscription, '50');

        $row = $this->row($period, $subscription);
        $this->assertSame([0, 0, 9000, 4000], [$row['accumulated'], $row['advance'], $row['beforePayments'], $row['remaining']]);
        $this->assertSame(4000, (int) round($subscription->balance() * 100));
    }

    public function test_case_5_a_positive_balance_is_carried_once_as_accumulated(): void
    {
        $subscription = $this->subscription();
        $this->openPeriod('2026-09-24', '2026-09-30');
        $this->invoice($subscription, 50);
        $second = $this->openPeriod('2026-10-01', '2026-10-07');
        $third = $this->openPeriod('2026-10-08', '2026-10-14');

        $this->assertSame([5000, 0, 5000], [$this->row($second, $subscription)['accumulated'], $this->row($second, $subscription)['advance'], $this->row($second, $subscription)['remaining']]);
        $this->assertSame([5000, 5000], [$this->row($third, $subscription)['accumulated'], $this->row($third, $subscription)['remaining']]);
    }

    public function test_case_6_a_negative_balance_is_carried_as_advance(): void
    {
        $subscription = $this->subscription();
        $this->openPeriod('2026-10-01', '2026-10-07');
        $this->pay($subscription, '30');
        $second = $this->openPeriod('2026-10-08', '2026-10-14');

        $row = $this->row($second, $subscription);
        $this->assertSame([0, 3000, -3000], [$row['accumulated'], $row['advance'], $row['remaining']]);
    }

    public function test_a_reversal_counts_in_the_period_it_is_made_and_leaves_the_closed_one_as_it_was(): void
    {
        $subscription = $this->subscription();
        $first = $this->openPeriod('2026-10-01', '2026-10-07');
        $this->invoice($subscription, 100);
        $this->pay($subscription, '40');
        $payment = $subscription->transactions()->where('type', SubscriptionTransaction::TYPE_PAYMENT)->sole();
        $second = $this->openPeriod('2026-10-08', '2026-10-14');
        app(BillingPeriods::class)->forget();
        $payment->cancel($this->manager, CorrectionReason::Other, 'دفعة خاطئة');

        $this->assertSame([4000, 6000], [$this->row($first, $subscription)['payments'], $this->row($first, $subscription)['remaining']]);
        $second = $this->row($second, $subscription);
        $this->assertSame([6000, -4000, 10000], [$second['accumulated'], $second['payments'], $second['remaining']]);
        $this->assertSame(10000, (int) round($subscription->balance() * 100));
    }

    public function test_an_open_period_stays_open_past_its_last_day_until_it_is_closed_by_hand(): void
    {
        $periods = app(BillingPeriods::class);
        $subscription = $this->subscription();
        $first = $periods->create($this->branch, CarbonImmutable::parse('2026-10-08'), CarbonImmutable::parse('2026-10-14'), $this->manager);
        $next = $periods->create($this->branch, CarbonImmutable::parse('2026-10-15'), CarbonImmutable::parse('2026-10-21'), $this->manager);

        // Weeks after its last day, and past the next one's first day, nothing has closed it.
        $this->travelTo(CarbonImmutable::parse('2026-11-30 23:00', config('app.business_timezone')));
        $this->artisan('billing-periods:open')->assertSuccessful();
        $periods->forget();
        $this->assertSame($first->id, $periods->open($this->branch->id)?->id);
        $this->assertSame(BillingPeriod::STATUS_SCHEDULED, $next->fresh()->status());
        $this->pay($subscription, '25');
        $this->assertSame($first->id, $subscription->transactions()->where('type', 'payment')->sole()->billing_period_id);

        // Closed by its manager, it takes no more payments; the next one, its first day come, opens at once.
        $this->post(route('billing-periods.close', $first))->assertSessionHasNoErrors()->assertSessionHas('status', 'billing-period-closed');
        $periods->forget();
        $this->assertTrue($first->fresh()->isClosed());
        $this->assertSame($next->id, $periods->open($this->branch->id)?->id);
        $this->pay($subscription, '10');
        $this->assertSame([$first->id, $next->id], $subscription->transactions()->where('type', 'payment')->orderBy('id')->pluck('billing_period_id')->all());
        $this->assertSame('2026-10-15', MeterReading::latestEndedWeekStart($this->branch->id)->toDateString());
    }

    public function test_no_payment_is_taken_while_no_period_is_open_and_the_closed_ones_stay_as_they_were(): void
    {
        $periods = app(BillingPeriods::class);
        $subscription = $this->subscription();
        $period = $this->openPeriod('2026-10-08', '2026-10-14');
        $this->pay($subscription, '40');
        $before = $this->row($period, $subscription);

        $periods->close($period, $this->manager);
        $periods->forget();
        $this->actingAs($this->manager)->post(route('subscriptions.payments.store', $subscription), ['amount' => '10', 'currency' => 'ILS', 'payment_method' => 'cash'])
            ->assertSessionHasErrors(['period' => 'لا توجد فترة مفتوحة في الفرع الآن؛ تُسجَّل الدفعات في الفترة المفتوحة فقط. يفتح مدير الفرع الفترة التالية من صفحة فترات الفوترة.']);
        try {
            SubscriptionTransaction::recordClearing($subscription, $this->manager, '5', 'خدمة');
            $this->fail('A clearing was taken with no period open.');
        } catch (ValidationException) {
        }
        $this->assertSame(1, $subscription->transactions()->whereIn('type', ['payment', 'clearing'])->count());
        $this->assertSame($before, $this->row($period, $subscription));

        // A period set up ahead opens on its first day, and payments are taken again.
        $next = $periods->create($this->branch, CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-26'), $this->manager);
        $this->assertSame(BillingPeriod::STATUS_SCHEDULED, $next->status());
        $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', config('app.business_timezone')));
        $periods->forget();
        $this->pay($subscription, '10');
        $this->assertSame($next->id, $subscription->transactions()->where('type', 'payment')->latest('id')->first()->billing_period_id);
    }

    public function test_readings_are_entered_while_the_period_is_open_on_any_day_and_not_once_it_is_closed(): void
    {
        // A Friday: before the branch has periods, its saved schedule (Thursdays) keeps entry shut.
        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00', config('app.business_timezone')));
        $reader = User::factory()->dataEntry()->for($this->branch)->create();
        $this->assertFalse($reader->can('create', MeterReading::class));

        // With a period open, the period is what lets readings in, not a weekday.
        $period = $this->openPeriod('2026-10-16', '2026-10-31');
        app(BillingPeriods::class)->forget();
        $this->assertTrue($reader->can('create', MeterReading::class));

        app(BillingPeriods::class)->close($period, $this->manager);
        app(BillingPeriods::class)->forget();
        $this->assertFalse($reader->can('create', MeterReading::class));
    }

    public function test_only_the_branch_manager_closes_a_period_and_only_an_open_one(): void
    {
        $period = $this->openPeriod('2026-10-08', '2026-10-14');
        $collector = User::factory()->collector()->for($this->branch)->create();
        $otherManager = User::factory()->branchAdmin()->for(Branch::factory())->create();

        $this->actingAs($collector)->post(route('billing-periods.close', $period))->assertForbidden();
        $this->actingAs($otherManager)->post(route('billing-periods.close', $period))->assertForbidden();
        $this->assertTrue($period->fresh()->isOpen());

        $this->actingAs($this->manager)->post(route('billing-periods.close', $period))->assertSessionHasNoErrors();
        $this->post(route('billing-periods.close', $period))->assertSessionHasErrors('period');
    }

    public function test_periods_never_overlap_and_only_one_waits_ahead(): void
    {
        $this->openPeriod('2026-10-08', '2026-10-14');

        $this->expectException(ValidationException::class);
        $this->openPeriod('2026-10-12', '2026-10-18');
    }

    public function test_a_closed_period_changes_by_its_manager_only_and_an_approved_one_never(): void
    {
        $subscription = $this->subscription();
        $collector = User::factory()->collector()->for($this->branch)->create();
        $first = $this->openPeriod('2026-10-01', '2026-10-07');
        $charge = SubscriptionTransaction::recordCharge($subscription, $this->manager, ChargeType::Penalty, '30', 'رسم');
        $this->openPeriod('2026-10-08', '2026-10-14');

        $this->actingAs($collector);
        app(BillingPeriods::class)->forget();
        $this->assertNotNull($charge->billingPeriodLockReason($collector));
        try {
            $charge->fresh()->update(['amount' => '25.00']);
            $this->fail('A collector changed a closed period.');
        } catch (ValidationException) {
        }

        $this->actingAs($this->manager);
        app(BillingPeriods::class)->forget();
        $this->assertNull($charge->fresh()->billingPeriodLockReason($this->manager));
        app(BillingPeriods::class)->approve($first->fresh(), $this->manager);
        app(BillingPeriods::class)->forget();
        $this->assertNotNull($charge->fresh()->billingPeriodLockReason($this->manager));
        $this->expectException(ValidationException::class);
        $charge->fresh()->update(['amount' => '25.00']);
    }

    public function test_approving_keeps_each_subscriptions_figures_and_only_once(): void
    {
        $subscription = $this->subscription();
        $first = $this->openPeriod('2026-10-01', '2026-10-07');
        $this->invoice($subscription, 100);
        $this->pay($subscription, '30');
        $this->openPeriod('2026-10-08', '2026-10-14');

        app(BillingPeriods::class)->approve($first->fresh(), $this->manager);
        $kept = SubscriberPeriodBalance::query()->where('billing_period_id', $first->id)->where('subscription_id', $subscription->id)->sole();
        $this->assertSame(['100.00', '30.00', '70.00'], [$kept->invoice, $kept->payments, $kept->closing]);
        $this->assertTrue($first->fresh()->isApproved());

        $this->expectException(ValidationException::class);
        app(BillingPeriods::class)->approve($first->fresh(), $this->manager);
    }

    public function test_branch_totals_are_the_sum_of_its_subscriptions(): void
    {
        $subscriptions = collect([$this->subscription(), $this->subscription(), $this->subscription()]);
        $this->openPeriod('2026-10-01', '2026-10-07');
        $subscriptions->each(fn (Subscription $subscription, int $index) => $this->invoice($subscription, 40 + $index * 10));
        $this->pay($subscriptions[0], '100');
        $period = $this->openPeriod('2026-10-08', '2026-10-14');
        $subscriptions->each(fn (Subscription $subscription) => $this->invoice($subscription, 25));
        $this->pay($subscriptions[1], '20');

        app(BillingPeriods::class)->forget();
        $rows = app(PeriodStatement::class)->rows($period->fresh());
        $totals = PeriodStatement::summarize($rows);
        $balances = (int) round($subscriptions->sum(fn (Subscription $subscription): float => $subscription->balance()) * 100);

        $this->assertSame($balances, $totals['remaining']);
        $this->assertSame($rows->sum('invoice'), $totals['invoice']);
        $this->assertSame(1, $totals['ahead']);
        $this->assertSame(2, $totals['owing']);
    }

    public function test_the_migration_turns_past_reading_weeks_into_periods_the_latest_left_open(): void
    {
        $migration = require database_path('migrations/2026_11_07_100000_create_billing_periods_tables.php');
        $migration->down();
        $subscription = $this->subscription();
        $old = SubscriptionTransaction::withoutEvents(fn () => SubscriptionTransaction::factory()->for($subscription)->create(['amount' => '15.00', 'created_at' => '2026-09-20 10:00:00']));
        foreach ([['2026-09-25', '2026-10-01', '2026-09-28 10:00:00'], ['2026-10-02', '2026-10-08', '2026-10-05 10:00:00']] as [$start, $end, $paidAt]) {
            MeterReading::factory()->for($subscription)->create(['week_start' => $start, 'week_end' => $end]);
            SubscriptionTransaction::withoutEvents(fn () => SubscriptionTransaction::factory()->for($subscription)->create([
                'type' => SubscriptionTransaction::TYPE_PAYMENT, 'source_key' => 'payment:'.$start, 'amount' => '-5.00', 'created_at' => $paidAt,
            ]));
        }

        $migration->up();

        $periods = BillingPeriod::query()->where('branch_id', $this->branch->id)->orderBy('starts_on')->get();
        $this->assertSame(['2026-09-25', '2026-10-02'], $periods->map(fn (BillingPeriod $period): string => $period->starts_on->toDateString())->all());
        $this->assertSame([BillingPeriod::STATUS_CLOSED, BillingPeriod::STATUS_OPEN], $periods->map->status()->all());
        $this->assertNull($old->fresh()->billing_period_id);
        $this->assertSame($periods->modelKeys(), MeterReading::query()->orderBy('week_start')->pluck('billing_period_id')->all());
        $this->assertSame($periods->modelKeys(), SubscriptionTransaction::query()->where('type', 'payment')->orderBy('created_at')->pluck('billing_period_id')->all());
        $this->assertSame([1500, 1000], [$this->row($periods[0], $subscription)['accumulated'], $this->row($periods[1], $subscription)['accumulated']]);
    }

    public function test_readings_are_entered_for_the_open_period_from_its_first_day_and_billed_in_it(): void
    {
        $subscription = $this->subscription();
        $period = $this->openPeriod('2026-10-08', '2026-10-14');

        $this->get(route('meter-readings.index'))->assertOk()->assertInertia(fn ($page) => $page->where('weekEnd', '2026-10-14')->where('canRecord', true));
        $this->post(route('meter-readings.store'), ['subscription_id' => $subscription->id, 'week_start' => '2026-10-08', 'current_reading' => 42])->assertSessionHasNoErrors();
        $reading = MeterReading::query()->sole();
        $this->assertSame([$period->id, '2026-10-14'], [$reading->billing_period_id, $reading->week_end->toDateString()]);

        $this->post(route('meter-readings.approve'), ['reading_ids' => [$reading->id]])->assertSessionHasNoErrors();
        $this->assertSame($period->id, $subscription->transactions()->where('type', SubscriptionTransaction::TYPE_METER_READING)->sole()->billing_period_id);
        $this->assertSame(4200, $this->row($period, $subscription)['invoice']);
    }

    public function test_the_period_pages_create_close_and_approve_periods_for_the_branch_manager_only(): void
    {
        $collector = User::factory()->collector()->for($this->branch)->create();
        $this->actingAs($collector)->post(route('billing-periods.store'), ['branch_id' => $this->branch->id, 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-14'])->assertForbidden();

        $this->actingAs($this->manager)->get(route('billing-periods.index'))->assertOk()->assertInertia(fn ($page) => $page->component('BillingPeriods/Index')->where('canManage', true));
        $this->post(route('billing-periods.store'), ['branch_id' => $this->branch->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-07'])->assertSessionHasNoErrors();
        $this->post(route('billing-periods.store'), ['branch_id' => $this->branch->id, 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-14'])->assertSessionHasNoErrors();
        $closed = BillingPeriod::query()->whereDate('starts_on', '2026-10-01')->sole();
        // The second waits while the first is open, and opens once the manager closes the first.
        $this->assertTrue($closed->fresh()->isOpen());
        $this->post(route('billing-periods.approve', $closed))->assertSessionHasErrors('period');
        $this->post(route('billing-periods.close', $closed))->assertSessionHasNoErrors();
        $this->assertTrue(BillingPeriod::query()->whereDate('starts_on', '2026-10-08')->sole()->isOpen());

        $this->actingAs($collector)->post(route('billing-periods.approve', $closed))->assertForbidden();
        $this->actingAs($this->manager)->post(route('billing-periods.approve', $closed))->assertSessionHasNoErrors();
        $this->assertTrue($closed->fresh()->isApproved());
    }

    public function test_the_report_and_dashboard_read_the_same_open_period_totals(): void
    {
        $subscription = $this->subscription();
        $this->openPeriod('2026-10-01', '2026-10-07');
        $this->invoice($subscription, 60);
        $this->openPeriod('2026-10-08', '2026-10-14');
        $this->invoice($subscription, 40);
        $this->pay($subscription, '30');
        $this->manager->permissions()->syncWithoutDetaching(Permission::idsFor([PermissionKey::ViewFinancialReports]));
        $this->manager->unsetRelation('permissions');

        $this->get(route('reports.remaining'))->assertOk()->assertInertia(fn ($page) => $page->component('Reports/RemainingAmounts')
            ->where('summary.invoice', '40.00')->where('summary.accumulated', '60.00')->where('summary.payments', '30.00')->where('summary.remaining', '70.00')
            ->where('rows.data.0.remaining', '70.00'));
        $this->get(route('dashboard'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('periodAccounts.totals.remaining', '70.00')->where('periodAccounts.counts.owing', 1)->where('periodAccounts.rates.total', 30));
    }

    public function test_a_line_recorded_before_any_period_opens_the_first_one(): void
    {
        $subscription = $this->subscription();
        SubscriptionTransaction::recordCharge($subscription, $this->manager, ChargeType::Penalty, '15', 'رسم قديم');
        $period = $this->openPeriod('2026-10-08', '2026-10-14');

        $this->assertSame(1500, $this->row($period, $subscription)['accumulated']);
        $this->assertSame(DailySeries::today()->toDateString(), '2026-10-08');
    }
}
