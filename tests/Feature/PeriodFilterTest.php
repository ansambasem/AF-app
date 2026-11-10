<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\BillingPeriod;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BillingPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The collection summary and the remaining amounts report pick a period by
 * the year, then the month, it was created in, then the period: only the
 * years and months the branch has periods in, its own and no other
 * branch's, opening on its open period.
 */
class PeriodFilterTest extends TestCase
{
    use RefreshDatabase;

    private Branch $north;

    private Branch $south;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', config('app.business_timezone')));
        $this->north = Branch::factory()->create(['name' => 'North']);
        $this->south = Branch::factory()->create(['name' => 'South']);
    }

    public function test_each_branch_offers_only_the_years_and_months_it_has_periods_in_and_opens_on_its_open_period(): void
    {
        $this->period($this->north, '2024-03-02', '2024-03-02', '2024-03-08');
        $this->period($this->north, '2025-07-12', '2025-07-12', '2025-07-18');
        $open = $this->period($this->north, '2026-11-01', '2026-11-01', '2026-11-07', open: true);
        $this->period($this->south, '2025-02-01', '2025-02-01', '2025-02-07');
        $this->period($this->south, '2026-11-03', '2026-11-03', '2026-11-09', open: true);

        $choices = $this->summaryChoices($this->manager($this->north));
        $this->assertSame(['2026', '2025', '2024'], array_column($choices['years'], 'value'));
        $this->assertSame(['2026', '2026-11', (string) $open->id], [$choices['year'], $choices['month'], $choices['value']]);

        // A branch that started in 2025 starts its years there, and never sees the other branch's periods.
        $south = $this->summaryChoices($this->manager($this->south));
        $this->assertSame(['2026', '2025'], array_column($south['years'], 'value'));
        $this->assertNotContains((string) $open->id, $this->values($south));
    }

    public function test_a_period_is_filed_under_the_month_it_was_created_not_the_days_it_covers(): void
    {
        $crossing = $this->period($this->north, '2026-10-30', '2026-10-30', '2026-11-05', createdAt: '2026-10-30 09:00');
        $open = $this->period($this->north, '2026-11-06', '2026-11-06', '2026-11-12', open: true, createdAt: '2026-11-06 09:00');

        $choices = $this->summaryChoices($this->manager($this->north));

        $this->assertSame([['2026-11', [(string) $open->id]], ['2026-10', [(string) $crossing->id]]], $this->months($choices, '2026'));
    }

    public function test_a_month_lists_all_its_periods_newest_first(): void
    {
        $first = $this->period($this->north, '2026-10-03', '2026-10-03', '2026-10-09');
        $second = $this->period($this->north, '2026-10-10', '2026-10-10', '2026-10-16');
        $third = $this->period($this->north, '2026-10-17', '2026-10-17', '2026-10-23', open: true);

        $this->assertSame([['2026-10', [(string) $third->id, (string) $second->id, (string) $first->id]]], $this->months($this->summaryChoices($this->manager($this->north)), '2026'));
    }

    public function test_picking_another_period_shows_its_figures_and_its_year_and_month(): void
    {
        $old = $this->period($this->north, '2024-03-02', '2024-03-02', '2024-03-08');
        $open = $this->period($this->north, '2026-11-01', '2026-11-01', '2026-11-07', open: true);
        $subscription = Subscription::factory()->create(['branch_id' => $this->north->id]);
        $manager = $this->manager($this->north);
        $this->paid($subscription, $manager, $old, '-40.00');
        $this->paid($subscription, $manager, $open, '-15.00');

        $this->actingAs($manager)->get(route('payments.summary'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.total', '15.00')->where('summary.period.key', (string) $open->id));
        $this->get(route('payments.summary', ['period' => $old->id]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.total', '40.00')
            ->where('summary.period.key', (string) $old->id)
            ->where('summary.options.periods.year', '2024')
            ->where('summary.options.periods.month', '2024-03')
            ->where('summary.options.periods.value', (string) $old->id));
        // Another branch's period is not one to pick: its figures never show, the page falls back to today's.
        $elsewhere = $this->period($this->south, '2026-11-01', '2026-11-01', '2026-11-07', open: true);
        $this->paid(Subscription::factory()->create(['branch_id' => $this->south->id]), $manager, $elsewhere, '-999.00');
        $this->get(route('payments.summary', ['period' => $elsewhere->id]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.period.key', 'today')->where('summary.total', '55.00'));
    }

    public function test_with_no_open_period_the_summary_keeps_showing_today_and_no_period_changes(): void
    {
        $closed = $this->period($this->north, '2026-10-03', '2026-10-03', '2026-10-09');
        $before = BillingPeriod::query()->get()->map->getAttributes()->all();

        $this->actingAs($this->manager($this->north))->get(route('payments.summary'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.period.key', 'today')
            ->where('summary.options.periods.year', null)
            ->where('summary.options.periods.value', '')
            ->where('summary.options.periods.years.0.months.0.periods.0.value', (string) $closed->id));
        $this->assertSame($before, BillingPeriod::query()->get()->map->getAttributes()->all());
    }

    public function test_the_remaining_amounts_report_picks_the_period_the_same_way_within_the_viewers_branches(): void
    {
        $old = $this->period($this->north, '2025-07-12', '2025-07-12', '2025-07-18');
        $open = $this->period($this->north, '2026-11-01', '2026-11-01', '2026-11-07', open: true);
        $this->period($this->south, '2024-01-06', '2024-01-06', '2024-01-12', open: true);
        $manager = $this->manager($this->north);
        $manager->permissions()->syncWithoutDetaching(Permission::idsFor([PermissionKey::ViewFinancialReports]));

        $this->actingAs($manager)->get(route('reports.remaining'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('periodChoices.years', fn ($years): bool => collect($years)->pluck('value')->all() === ['2026', '2025'])
            ->where('periodChoices.value', '2026-11-01')
            ->where('periodChoices.month', '2026-11')
            ->where('branchRows.0.period.label', $open->label()));
        $this->get(route('reports.remaining', ['date' => '2025-07-12']))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('periodChoices.year', '2025')
            ->where('periodChoices.month', '2025-07')
            ->where('branchRows.0.period.label', $old->label()));
    }

    /** A period of `$branch` created at `$createdAt` (its first day by default), closed unless `$open`. */
    private function period(Branch $branch, string $createdOn, string $startsOn, string $endsOn, bool $open = false, ?string $createdAt = null): BillingPeriod
    {
        $created = CarbonImmutable::parse($createdAt ?? $createdOn.' 08:00', config('app.business_timezone'));
        $period = BillingPeriod::factory()->for($branch)->create([
            'starts_on' => $startsOn, 'ends_on' => $endsOn, 'opened_at' => $created,
            'closed_at' => $open ? null : CarbonImmutable::parse($endsOn, config('app.business_timezone'))->addDay(),
            'created_at' => $created, 'updated_at' => $created,
        ]);
        app(BillingPeriods::class)->forget();

        return $period;
    }

    private function manager(Branch $branch): User
    {
        return User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);
    }

    private function paid(Subscription $subscription, User $collector, BillingPeriod $period, string $amount): void
    {
        SubscriptionTransaction::factory()->for($subscription)->create([
            'type' => 'payment', 'payment_method' => 'cash', 'amount' => $amount, 'recorded_by' => $collector->id,
            'billing_period_id' => $period->id, 'source_key' => 'test:'.Str::ulid(),
        ]);
    }

    /** @return array<string, mixed> */
    private function summaryChoices(User $viewer): array
    {
        return $this->actingAs($viewer)->get(route('payments.summary'))->assertOk()->inertiaProps('summary.options.periods');
    }

    /** @return list<array{0: string, 1: list<string>}> each month of `$year` and its periods */
    private function months(array $choices, string $year): array
    {
        $ofYear = collect($choices['years'])->firstWhere('value', $year);

        return collect($ofYear['months'])->map(fn (array $month): array => [$month['value'], array_column($month['periods'], 'value')])->all();
    }

    /** @return list<string> */
    private function values(array $choices): array
    {
        return collect($choices['years'])->flatMap(fn (array $year) => collect($year['months'])->flatMap(fn (array $month) => array_column($month['periods'], 'value')))->all();
    }
}
