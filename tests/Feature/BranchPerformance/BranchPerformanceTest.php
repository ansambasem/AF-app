<?php

namespace Tests\Feature\BranchPerformance;

use App\Enums\PermissionKey;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\MeterReading;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class BranchPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $karrada;

    private User $admin;

    private User $clerk;

    private User $collector;

    private Branch $mansour;

    protected function setUp(): void
    {
        parent::setUp();

        // 15:00 on Sunday 20 September in Gaza (UTC+3), the business's time zone.
        $this->travelTo('2026-09-20 12:00:00');

        $this->karrada = Branch::factory()->create(['name' => 'فرع الكرادة']);
        $this->admin = User::factory()->branchAdmin()->create(['branch_id' => $this->karrada->id, 'name' => 'Karrada Admin']);
        $this->clerk = User::factory()->dataEntry()->create(['branch_id' => $this->karrada->id, 'name' => 'Karrada Clerk']);
        $this->collector = User::factory()->collector()->create(['branch_id' => $this->karrada->id, 'name' => 'Former Collector', 'is_active' => false]);

        $this->mansour = Branch::factory()->create(['name' => 'فرع المنصور', 'is_active' => false]);
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->get(route('branch-performance.index'))->assertRedirect(route('login'));
        $this->get(route('branch-performance.show', $this->karrada))->assertRedirect(route('login'));
    }

    public function test_the_pages_take_their_own_view_branch_performance_permission(): void
    {
        $this->actingAs($this->clerk)->get(route('branch-performance.index'))->assertForbidden();
        $this->actingAs($this->clerk)->get(route('branch-performance.show', $this->karrada))->assertForbidden();

        $this->clerk->permissions()->attach(Permission::idsFor([PermissionKey::ViewCollections]));
        $this->actingAs($this->clerk->fresh())->get(route('branch-performance.show', $this->karrada))->assertForbidden();

        $this->clerk->permissions()->attach(Permission::idsFor([PermissionKey::ViewBranchPerformance]));
        $this->actingAs($this->clerk->fresh())->get(route('branch-performance.show', $this->karrada))->assertOk();
    }

    public function test_branch_staff_go_straight_to_their_own_branch_and_no_other(): void
    {
        $this->actingAs($this->admin)
            ->get(route('branch-performance.index'))
            ->assertRedirect(route('branch-performance.show', $this->karrada));

        $this->actingAs($this->admin)->get(route('branch-performance.show', $this->mansour))->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('branch-performance.show', $this->karrada))
            ->assertInertia(fn ($page) => $page->component('BranchPerformance/Show')->where('canCompareBranches', false));
    }

    public function test_the_overview_gives_each_branch_its_figures_and_the_totals_across_branches(): void
    {
        $this->seedActivity();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('branch-performance.index'))
            ->assertInertia(fn ($page) => $page
                ->component('BranchPerformance/Index')
                ->where('sort', 'revenue')
                ->where('summary', [
                    'branches' => 2,
                    'activeBranches' => 1,
                    'chargesTotal' => 700,
                    'subscriptions' => 4,
                    'activeSubscriptions' => 3,
                    'weekEntries' => 3,
                    'todayEntries' => 2,
                    'staff' => 3,
                ])
                ->where('branches.0.name', 'فرع المنصور')
                ->where('branches.0.rank', 1)
                ->where('branches.1', fn ($branch): bool => $branch['name'] === 'فرع الكرادة'
                    && $branch['rank'] === 2
                    && $branch['chargesTotal'] == 200
                    && $branch['subscriptions'] === 3
                    && $branch['activeSubscriptions'] === 2
                    && $branch['staff'] === 2
                    && $branch['todayEntries'] === 2
                    && $branch['weekEntries'] === 3
                    && count($branch['sparkline']) === 14
                    && $branch['sparkline'][13] === 2
                    && $branch['sparkline'][10] === 1
                    && $branch['sparkline'][3] === 1
                    && array_sum($branch['sparkline']) === 4
                    && $branch['lastEntryAt'] === '2026-09-20T09:30:00+00:00'));
    }

    /**
     * @param  array<int, string>  $order
     */
    #[TestWith(['revenue', ['فرع المنصور', 'فرع الكرادة']])]
    #[TestWith(['subscriptions', ['فرع الكرادة', 'فرع المنصور']])]
    #[TestWith(['activity', ['فرع الكرادة', 'فرع المنصور']])]
    #[TestWith(['nonsense', ['فرع المنصور', 'فرع الكرادة']])]
    public function test_the_branches_are_ranked_by_the_chosen_figure(string $sort, array $order): void
    {
        $this->seedActivity();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('branch-performance.index', ['sort' => $sort]))
            ->assertInertia(fn ($page) => $page
                ->where('sort', $sort === 'nonsense' ? 'revenue' : $sort)
                ->where('branches', fn ($branches): bool => collect($branches)->pluck('name')->all() === $order
                    && collect($branches)->pluck('rank')->all() === [1, 2]));
    }

    public function test_a_branch_page_shows_its_subscriptions_charges_and_entries_over_the_last_month(): void
    {
        $this->seedActivity();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('branch-performance.show', $this->karrada))
            ->assertInertia(fn ($page) => $page
                ->component('BranchPerformance/Show')
                ->where('canCompareBranches', true)
                ->where('branch.statusCounts', [
                    ['value' => 'active', 'label' => 'نشط', 'count' => 2],
                    ['value' => 'suspended', 'label' => 'قيد الانتظار', 'count' => 1],
                    ['value' => 'disconnected', 'label' => 'مفصول', 'count' => 0],
                ])
                ->where('branch.chargesTotal', 200)
                ->where('branch.monthChargesTotal', 170)
                ->where('branch.todayEntries', 2)
                ->where('branch.weekEntries', 3)
                ->where('branch.monthEntries', 4)
                ->has('dailyRegistrations', 30)
                ->where('dailyRegistrations.29', ['date' => '2026-09-20', 'value' => 1])
                ->where('dailyRegistrations.26', ['date' => '2026-09-17', 'value' => 1])
                ->has('dailyCharges', 30)
                ->where('dailyCharges.29', ['date' => '2026-09-20', 'value' => 50, 'count' => 1])
                ->where('dailyCharges.19', ['date' => '2026-09-10', 'value' => 120, 'count' => 1]));
    }

    public function test_the_team_lists_the_branchs_staff_busiest_first_with_what_each_recorded(): void
    {
        $this->seedActivity();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('branch-performance.show', $this->karrada))
            ->assertInertia(fn ($page) => $page
                ->where('team', fn ($team): bool => collect($team)->map(fn (array $member): array => [
                    $member['name'], $member['entries'], $member['weekEntries'],
                    $member['recordedCount'], $member['recordedCharged'], $member['recordedCredited'], $member['lastActivityAt'],
                ])->all() === [
                    ['Karrada Clerk', 3, 2, 2, 80, 0, '2026-09-20T09:30:00+00:00'],
                    ['Karrada Admin', 2, 1, 2, 120, 10, '2026-09-20T10:00:00+00:00'],
                    ['Former Collector', 0, 0, 1, 0, 100, '2026-09-20T11:00:00+00:00'],
                ]));
    }

    public function test_the_work_log_covers_two_weeks_newest_first_with_each_days_busiest_member(): void
    {
        $this->seedActivity();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('branch-performance.show', $this->karrada))
            ->assertInertia(fn ($page) => $page
                ->has('workLog', 14)
                ->where('workLog.0', [
                    'date' => '2026-09-20', 'newSubscriptions' => 1, 'entries' => 2, 'chargesCount' => 1, 'chargesTotal' => 50, 'topEntrant' => 'Karrada Clerk',
                ])
                ->where('workLog.3', [
                    'date' => '2026-09-17', 'newSubscriptions' => 1, 'entries' => 1, 'chargesCount' => 0, 'chargesTotal' => 0, 'topEntrant' => 'Karrada Admin',
                ])
                ->where('workLog.10', [
                    'date' => '2026-09-10', 'newSubscriptions' => 0, 'entries' => 1, 'chargesCount' => 1, 'chargesTotal' => 120, 'topEntrant' => 'Karrada Admin',
                ])
                ->where('workLog.13.date', '2026-09-07')
                ->where('workLog.13.topEntrant', null)
                ->where('latestRegistrations', fn ($subscriptions): bool => collect($subscriptions)
                    ->map(fn (array $subscription): string => $subscription['name'].' / '.$subscription['registeredByName'])
                    ->all() === ['Registered Today / Karrada Clerk', 'Registered This Week / Karrada Admin', 'Registered Long Ago / Karrada Clerk']));
    }

    public function test_entries_fall_on_the_business_day_they_were_made(): void
    {
        // 01:30 on the 20th in Gaza, though still the 19th in UTC.
        $this->reading($this->subscription(SubscriptionStatus::Active, $this->clerk, '2026-08-01 09:00:00'), $this->clerk, '2026-09-19 22:30:00');
        // The last second of the 13th in Gaza (outside the 7 days), then the first of the 14th (inside).
        $this->subscription(SubscriptionStatus::Active, $this->clerk, '2026-09-13 20:59:59');
        $this->subscription(SubscriptionStatus::Active, $this->clerk, '2026-09-13 21:00:00');

        $this->actingAs($this->admin)
            ->get(route('branch-performance.show', $this->karrada))
            ->assertInertia(fn ($page) => $page
                ->where('branch.todayEntries', 1)
                ->where('branch.weekEntries', 2)
                ->where('workLog.0.entries', 1)
                ->where('workLog.6', fn ($day): bool => $day['date'] === '2026-09-14' && $day['newSubscriptions'] === 1));
    }

    /**
     * Karrada: three subscriptions (one registered today, one three days ago,
     * one fifty days ago and since suspended), a reading today and one ten
     * days ago, 200 shekels charged (170 of it in the last 30 days), plus a
     * payment and a discount. Mansour, closed: one subscription and a 500
     * shekel fee, both forty days old.
     */
    private function seedActivity(): void
    {
        $today = $this->subscription(SubscriptionStatus::Active, $this->clerk, '2026-09-20 08:00:00', 'Registered Today');
        $thisWeek = $this->subscription(SubscriptionStatus::Active, $this->admin, '2026-09-17 07:00:00', 'Registered This Week');
        $longAgo = $this->subscription(SubscriptionStatus::Suspended, $this->clerk, '2026-08-01 07:00:00', 'Registered Long Ago');

        $this->reading($longAgo, $this->clerk, '2026-09-20 09:30:00');
        $this->reading($thisWeek, $this->admin, '2026-09-10 10:00:00');

        $this->line($today, 'subscription_fee', '50.00', $this->clerk, '2026-09-20 08:00:00');
        $this->line($thisWeek, 'meter_reading', '120.00', $this->admin, '2026-09-10 10:00:00');
        $this->line($longAgo, 'subscription_fee', '30.00', $this->clerk, '2026-08-01 07:00:00');
        $this->line($today, 'payment', '-100.00', $this->collector, '2026-09-20 11:00:00');
        $this->line($thisWeek, 'discount', '-10.00', $this->admin, '2026-09-20 10:00:00');

        $mansourAdmin = User::factory()->branchAdmin()->create(['branch_id' => $this->mansour->id]);
        $mansourSubscription = Subscription::factory()->create([
            'branch_id' => $this->mansour->id,
            'registered_by' => $mansourAdmin->id,
            'meter_box_id' => null,
            'created_at' => '2026-08-11 09:00:00',
        ]);
        $this->line($mansourSubscription, 'subscription_fee', '500.00', $mansourAdmin, '2026-08-11 09:00:00');
    }

    private function subscription(SubscriptionStatus $status, User $registeredBy, string $at, ?string $name = null): Subscription
    {
        return Subscription::factory()->create([
            'branch_id' => $this->karrada->id,
            'registered_by' => $registeredBy->id,
            'meter_box_id' => null,
            'status' => $status,
            'full_name' => $name ?? fake()->name(),
            'created_at' => $at,
        ]);
    }

    private function reading(Subscription $subscription, User $recordedBy, string $at): MeterReading
    {
        return MeterReading::factory()->for($subscription)->create([
            'branch_id' => $subscription->branch_id,
            'recorded_by' => $recordedBy->id,
            'created_at' => $at,
        ]);
    }

    private function line(Subscription $subscription, string $type, string $amount, User $recordedBy, string $at): SubscriptionTransaction
    {
        return SubscriptionTransaction::factory()->for($subscription)->create([
            'type' => $type,
            'source_key' => $type.':'.Str::ulid(),
            'amount' => $amount,
            'recorded_by' => $recordedBy->id,
            'created_at' => $at,
        ]);
    }
}
