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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_super_admin_sees_the_whole_company_and_each_branchs_collections(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        [$north, $south] = Branch::factory()->count(2)->create();
        $this->pay($north, 100);
        $this->pay($south, 40);

        $this->actingAs($superAdmin)->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->where('scope.company', true)
            ->where('scope.label', 'كل الفروع')
            ->where('sections.collections.total', 140)
            ->where('sections.collections.byBranch.0.name', $north->name)
            ->where('sections.collections.byBranch.0.share', 71)
            ->where('branchOptions', fn ($options): bool => collect($options)->pluck('value')->contains($south->id))
            ->where('latestPayments.sum', 140));
    }

    public function test_the_super_admin_can_narrow_the_figures_to_one_branch(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        [$north, $south] = Branch::factory()->count(2)->create();
        $this->pay($north, 100);
        $this->pay($south, 40);

        $this->actingAs($superAdmin)->get(route('dashboard', ['branch' => $south->id, 'days' => 7]))->assertInertia(fn ($page) => $page
            ->where('scope.label', $south->name)
            ->where('filters.days', 7)
            ->where('sections.collections.total', 40)
            ->where('sections.collections.byBranch', null)
            ->has('latestPayments.rows.data', 1));
    }

    public function test_a_branch_manager_sees_only_their_branch(): void
    {
        [$own, $other] = Branch::factory()->count(2)->create();
        $manager = User::factory()->branchAdmin()->create(['branch_id' => $own->id]);
        $this->pay($own, 75);
        $this->pay($other, 500);

        $this->actingAs($manager)->get(route('dashboard', ['branch' => $other->id]))->assertInertia(fn ($page) => $page
            ->where('scope.company', false)
            ->where('scope.label', $own->name)
            ->where('branchOptions', null)
            ->where('sections.collections.total', 75)
            ->where('sections.subscriptions.active', 1)
            ->has('latestPayments.rows.data', 1));
    }

    public function test_each_part_needs_the_permission_of_the_page_it_summarizes(): void
    {
        $branch = Branch::factory()->create();
        $clerk = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $clerk->permissions()->sync(Permission::idsFor([PermissionKey::ViewSubscriptions]));
        $this->pay($branch, 30);

        $this->actingAs($clerk)->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->has('sections.subscriptions')
            ->missing('sections.collections')
            ->missing('sections.readings')
            ->where('latestPayments', null));
    }

    public function test_the_latest_payments_are_searched_and_narrowed_to_cash_or_transfers(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create();
        $this->pay($branch, 20, ['full_name' => 'Samir Haddad']);
        $this->pay($branch, 35, ['full_name' => 'Rana Odeh']);

        $this->actingAs($superAdmin)->get(route('dashboard', ['search' => 'Rana']))->assertInertia(fn ($page) => $page
            ->has('latestPayments.rows.data', 1)
            ->where('latestPayments.rows.data.0.subscriber', 'Rana Odeh'));
        $this->get(route('dashboard', ['method' => 'transfer']))->assertInertia(fn ($page) => $page->has('latestPayments.rows.data', 0));
    }

    public function test_the_reading_week_counts_approved_waiting_and_missing_readings_and_debts_follow_the_page(): void
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
            ->where('sections.readings.missing', 1)
            ->missing('debts')
            ->loadDeferredProps(fn ($reload) => $reload->has('debts.total')));
    }

    public function test_a_branch_sees_what_each_employee_collected_over_the_period(): void
    {
        $branch = Branch::factory()->create();
        $manager = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);
        $this->pay($branch, 30);
        $this->pay($branch, 90);

        $this->actingAs($manager)->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->has('sections.collections.byCollector', 2)
            ->where('sections.collections.byCollector.0.total', 90)
            ->where('sections.collections.byCollector.0.share', 75)
            ->where('sections.collections.byBranch', null));
    }

    public function test_the_debts_name_the_subscribers_who_owe_the_most(): void
    {
        $branch = Branch::factory()->create();
        $manager = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);
        $small = Subscription::factory()->create(['branch_id' => $branch->id, 'full_name' => 'Small debtor']);
        $large = Subscription::factory()->create(['branch_id' => $branch->id, 'full_name' => 'Large debtor']);
        foreach ([[$small, '20.00'], [$large, '80.00']] as [$subscription, $amount]) {
            SubscriptionTransaction::factory()->for($subscription)->create([
                'type' => 'charge', 'source_key' => 'charge:'.$subscription->id, 'amount' => $amount, 'currency_amount' => $amount, 'recorded_by' => $manager->id,
            ]);
        }

        $this->actingAs($manager)->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('debts.total', 100)
                ->where('debts.top.0.subscriptionId', $large->id)
                ->where('debts.top.0.balance', 80)
                ->where('debts.top.1.subscriptionId', $small->id)));
    }

    /**
     * A cash payment of `$amount` on a new active subscription of the branch.
     *
     * @param  array<string, mixed>  $subscription
     */
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

    public function test_a_subscribers_statement_opens_over_the_dashboard_with_an_edit_button_for_who_may_edit(): void
    {
        [$own, $other] = Branch::factory()->count(2)->create();
        $manager = User::factory()->branchAdmin()->create(['branch_id' => $own->id]);
        $mine = Subscription::factory()->create(['branch_id' => $own->id]);
        $foreign = Subscription::factory()->create(['branch_id' => $other->id]);

        $this->actingAs(User::factory()->superAdmin()->create())->get(route('dashboard', ['statement' => $mine->id]))
            ->assertInertia(fn ($page) => $page->where('statement.subscription.id', $mine->id)->where('statement.canUpdate', true));

        $this->actingAs($manager)->get(route('dashboard', ['statement' => $foreign->id]))
            ->assertInertia(fn ($page) => $page->where('statement', null));
    }

    public function test_the_edit_subscriber_pop_up_loads_only_a_subscriber_the_user_may_edit(): void
    {
        [$own, $other] = Branch::factory()->count(2)->create();
        $manager = User::factory()->branchAdmin()->create(['branch_id' => $own->id]);
        $mine = Subscription::factory()->create(['branch_id' => $own->id, 'full_name' => 'Editable subscriber']);
        $foreign = Subscription::factory()->create(['branch_id' => $other->id]);

        $this->actingAs($manager)->get(route('dashboard', ['edit' => $mine->id]))->assertInertia(fn ($page) => $page
            ->missing('subscriptionEdit')
            ->reloadOnly(['subscriptionEdit', 'subscriptionForm'], fn ($reload) => $reload
                ->where('subscriptionEdit.id', $mine->id)
                ->where('subscriptionEdit.full_name', 'Editable subscriber')
                ->has('subscriptionForm.tariffs')));

        $this->actingAs($manager)->get(route('dashboard', ['edit' => $foreign->id]))->assertInertia(fn ($page) => $page
            ->reloadOnly('subscriptionEdit', fn ($reload) => $reload->where('subscriptionEdit', null)));

        $viewer = User::factory()->collector()->create(['branch_id' => $own->id]);
        $viewer->permissions()->sync(Permission::idsFor([PermissionKey::ViewSubscriptions]));
        $this->actingAs($viewer)->get(route('dashboard', ['edit' => $mine->id]))->assertInertia(fn ($page) => $page
            ->reloadOnly(['subscriptionEdit', 'subscriptionForm'], fn ($reload) => $reload->where('subscriptionEdit', null)->where('subscriptionForm', null)));
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
