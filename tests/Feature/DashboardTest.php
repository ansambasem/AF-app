<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\Governorate;
use App\Models\MeterReading;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BillingPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_banner_names_the_governorate_the_super_admin_narrowed_to(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $governorate = Governorate::create(['name' => 'محافظة الوسطى']);
        [, $second] = Branch::factory()->count(2)->create(['governorate_id' => $governorate->id]);
        Branch::factory()->create();

        $this->actingAs($superAdmin)->get(route('dashboard', ['governorate' => $governorate->id]))->assertInertia(fn ($page) => $page
            ->where('branchScope.level', 'governorate')
            ->where('branchScope.name', 'محافظة الوسطى')
            ->where('branchScope.branches', 2));
        $this->get(route('dashboard', ['governorate' => $governorate->id, 'branch' => $second->id]))->assertInertia(fn ($page) => $page
            ->where('branchScope.level', 'branch')
            ->where('branchScope.name', $second->name));
        // A governorate with no branch yet still names itself, so the banner and its pickers stay to choose another.
        $empty = Governorate::create(['name' => 'محافظة الشمال']);
        $this->get(route('dashboard', ['governorate' => $empty->id]))->assertInertia(fn ($page) => $page
            ->where('branchScope.level', 'governorate')
            ->where('branchScope.name', 'محافظة الشمال')
            ->where('branchScope.branches', 0));
    }

    /**
     * A cash payment of `$amount` on a new active subscription of the branch.
     *
     * @param  array<string, mixed>  $subscription
     */
    public function test_the_super_admin_sees_the_whole_company_and_the_open_periods_follow_the_branch_picked_at_the_top(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        [$north, $south] = Branch::factory()->count(2)->create();
        foreach ([$north, $south] as $branch) {
            app(BillingPeriods::class)->create($branch, CarbonImmutable::today(config('app.business_timezone')), CarbonImmutable::today(config('app.business_timezone'))->addDays(6), $superAdmin);
        }
        $this->pay($north, 100);
        $this->pay($south, 40);

        $this->actingAs($superAdmin)->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->where('scope.company', true)
            ->where('scope.label', 'كل الفروع')
            ->where('branchScope.level', 'all')
            ->where('branchScope.name', 'جميع الفروع')
            ->where('branchScope.branches', Branch::count())
            ->where('branchOptions', fn ($options): bool => collect($options)->pluck('value')->contains($south->id))
            ->where('periodAccounts.totals.payments', '140.00'));

        $this->get(route('dashboard', ['branch' => $south->id]))->assertInertia(fn ($page) => $page
            ->where('scope.label', $south->name)
            ->where('branchScope.level', 'branch')
            ->where('branchScope.name', $south->name)
            ->where('filters.branch', (string) $south->id)
            ->where('periodAccounts.totals.payments', '40.00'));
    }

    public function test_a_branch_manager_sees_only_their_branch(): void
    {
        [$own, $other] = Branch::factory()->count(2)->create();
        $manager = User::factory()->branchAdmin()->create(['branch_id' => $own->id]);
        app(BillingPeriods::class)->create($own, CarbonImmutable::today(config('app.business_timezone')), CarbonImmutable::today(config('app.business_timezone'))->addDays(6), $manager);
        $this->pay($own, 75);
        $this->pay($other, 500);

        $this->actingAs($manager)->get(route('dashboard', ['branch' => $other->id]))->assertInertia(fn ($page) => $page
            ->where('scope.company', false)
            ->where('scope.label', $own->name)
            ->where('branchScope', null)
            ->where('branchOptions', null)
            ->where('periodAccounts.totals.payments', '75.00'));
    }

    public function test_the_removed_parts_are_neither_shown_nor_worked_out(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $this->pay(Branch::factory()->create(), 30);

        $this->actingAs($superAdmin)->get(route('dashboard', ['days' => 7, 'method' => 'cash']))->assertOk()->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->missing('sections.collections')
            ->missing('sections.subscriptions')
            ->missing('sections.meterBoxes')
            ->missing('debts')
            ->missing('latestPayments')
            ->missing('periods')
            ->missing('statement')
            ->missing('subscriptionForm')
            ->missing('subscriptionEdit')
            ->missing('canCreateSubscription')
            ->missing('filters.days'));
    }

    public function test_each_part_needs_the_permission_of_the_page_it_summarizes(): void
    {
        $branch = Branch::factory()->create();
        $clerk = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $clerk->permissions()->sync(Permission::idsFor([PermissionKey::ViewSubscriptions]));
        $this->pay($branch, 30);

        $this->actingAs($clerk)->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->where('sections', [])
            ->where('periodAccounts', null));
    }

    public function test_the_reading_week_counts_approved_waiting_and_missing_readings(): void
    {
        $branch = Branch::factory()->create();
        $manager = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);
        [$read, $waiting] = Subscription::factory()->count(3)->create(['branch_id' => $branch->id, 'status' => 'active']);
        $week = MeterReading::latestEndedWeekStart($branch);
        MeterReading::factory()->approved()->create(['subscription_id' => $read->id, 'week_start' => $week]);
        MeterReading::factory()->create(['subscription_id' => $waiting->id, 'week_start' => $week]);

        $this->actingAs($manager)->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->where('sections.readings.total', 3)
            ->where('sections.readings.approved', 1)
            ->where('sections.readings.pending', 1)
            ->where('sections.readings.missing', 1));
    }

    private function pay(Branch $branch, float $amount, array $subscription = []): void
    {
        $recorder = User::factory()->collector()->create(['branch_id' => $branch->id]);
        SubscriptionTransaction::recordPayment(
            Subscription::factory()->create(['branch_id' => $branch->id, 'status' => 'active', ...$subscription]),
            $recorder,
            ['amount' => (string) $amount, 'currency' => 'ILS', 'payment_method' => 'cash'],
        );
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_a_field_user_with_nothing_to_see_is_pointed_to_the_field_app(): void
    {
        config(['powercollect.mobile_app_url' => 'https://example.test/app.apk']);
        $collector = User::factory()->collector()->withPermissions([PermissionKey::RecordCollections])->create();

        $this->actingAs($collector)->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->where('sections', [])
            ->where('fieldApp.url', 'https://example.test/app.apk'));

        config(['powercollect.mobile_app_url' => null]);
        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('fieldApp.url', null));
    }

    public function test_the_field_app_notice_is_only_for_field_users_who_have_nothing_else_to_see(): void
    {
        $nothing = User::factory()->collector()->create();
        $nothing->permissions()->sync([]);
        $this->actingAs($nothing)->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('fieldApp', null));

        $branchAdmin = User::factory()->branchAdmin()->withPermissions([PermissionKey::RecordCollections])->create();
        $this->actingAs($branchAdmin)->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('fieldApp', null));
    }

    public function test_the_new_branch_pop_up_loads_its_options_only_when_opened(): void
    {
        Governorate::factory()->create(['name' => 'Baghdad']);
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('canCreateBranch', true)
            ->missing('branchForm')
            ->reloadOnly('branchForm', fn ($reload) => $reload->where('branchForm.governorates.0.name', 'Baghdad')));
    }

    public function test_the_new_user_pop_up_keeps_the_branch_fixed_without_a_system_role_selector(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();

        $response = $this->actingAs($branchAdmin)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->missing('userForm')
            ->reloadOnly('userForm', fn ($reload) => $reload
                ->where('userForm.canChooseBranch', false)
                ->missing('userForm.roleOptions')
                ->has('userForm.userTypeOptions')));
    }

    public function test_someone_who_cannot_create_branches_gets_no_branch_pop_up_options(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();

        $response = $this->actingAs($branchAdmin)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page->reloadOnly('branchForm', fn ($reload) => $reload->where('branchForm', null)));
    }

    public function test_a_save_from_a_dashboard_pop_up_returns_to_the_dashboard_and_nowhere_else(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $save = fn (?string $returnTo, string $name) => $this->actingAs($superAdmin)
            ->post(route('branches.store'), ['name' => $name], $returnTo === null ? [] : ['X-Return-To' => $returnTo]);

        $save('/dashboard?statement=5', 'From the dashboard')->assertRedirect(route('dashboard').'?statement=5')->assertSessionHas('status', 'branch-created');
        $save(null, 'From the branches page')->assertRedirect(route('branches.index'));

        foreach (['https://elsewhere.example/dashboard', '//elsewhere.example/dashboard', '/subscriptions', 'dashboard'] as $index => $target) {
            $save($target, "Outside target {$index}")->assertRedirect(route('branches.index'));
        }
    }
}
