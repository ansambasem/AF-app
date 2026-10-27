<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DisconnectionList;
use App\Models\DisconnectionListItem;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The branch banner of the list pages that narrow to one branch with
 * `filter[branch_id]`: which branch it names, for whom, and within which
 * branches.
 */
class ListPageBranchBannerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_super_admin_reads_which_branch_the_subscriber_list_holds(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        [, $south] = Branch::factory()->count(2)->create();
        Subscription::factory()->count(2)->create(['branch_id' => $south->id]);

        $this->actingAs($superAdmin)->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page
            ->where('branchScope.level', 'all')
            ->where('branchScope.name', 'جميع الفروع')
            ->where('branchScope.allName', 'جميع الفروع')
            ->where('branchScope.branches', Branch::count()));
        $this->get(route('subscriptions.index', ['filter' => ['branch_id' => $south->id]]))->assertInertia(fn ($page) => $page
            ->where('branchScope.level', 'branch')
            ->where('branchScope.name', $south->name)
            ->where('branchScope.subscribers', 2));
        $this->get(route('subscriptions.index', ['filter' => ['branch_id' => 'not-a-branch']]))->assertInertia(fn ($page) => $page
            ->where('branchScope.level', 'all'));
    }

    public function test_an_auditor_of_several_branches_keeps_the_pages_as_they_were(): void
    {
        [$first, $second] = Branch::factory()->count(2)->create();
        Branch::factory()->create();
        $auditor = $this->auditorOf([$first, $second], ['view_accounts', 'view_ledger']);

        $this->actingAs($auditor);
        foreach (['subscriptions.index', 'ledger.index'] as $route) {
            $this->get(route($route, ['filter' => ['branch_id' => $second->id]]))->assertOk()->assertInertia(fn ($page) => $page
                ->where('branchScope', null));
        }
    }

    public function test_the_super_admin_reads_which_branch_the_ledger_holds(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($superAdmin)->get(route('ledger.index', ['filter' => ['branch_id' => $branch->id]]))->assertInertia(fn ($page) => $page
            ->where('branchScope.level', 'branch')
            ->where('branchScope.name', $branch->name));
    }

    public function test_the_super_admin_reads_which_branch_the_debts_and_meter_boxes_hold(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        [$branch] = Branch::factory()->count(2)->create();

        $this->actingAs($superAdmin);
        foreach (['receivables.index', 'meter-boxes.index', 'disconnection-lists.index'] as $route) {
            $this->get(route($route))->assertInertia(fn ($page) => $page
                ->where('branchScope.level', 'all'));
            $this->get(route($route, ['filter' => ['branch_id' => $branch->id]]))->assertInertia(fn ($page) => $page
                ->where('branchScope.level', 'branch')
                ->where('branchScope.name', $branch->name));
        }
    }

    public function test_the_disconnection_figures_follow_the_branch_the_banner_names(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        [$north, $south] = Branch::factory()->count(2)->create();
        foreach ([$north, $north, $south] as $branch) {
            DisconnectionListItem::factory()->for(DisconnectionList::factory()->create(['branch_id' => $branch->id]), 'list')->create();
        }

        $this->actingAs($superAdmin)->get(route('disconnection-lists.index'))->assertInertia(fn ($page) => $page
            ->where('summary.openLists', 3)
            ->where('summary.scheduled', 3));
        $this->get(route('disconnection-lists.index', ['filter' => ['branch_id' => $north->id]]))->assertInertia(fn ($page) => $page
            ->where('summary.openLists', 2)
            ->where('summary.scheduled', 2));
    }

    public function test_someone_who_sees_only_their_own_branch_gets_no_banner(): void
    {
        $manager = User::factory()->branchAdmin()->create();

        $this->actingAs($manager);
        foreach (['subscriptions.index', 'ledger.index', 'receivables.index', 'meter-boxes.index', 'disconnection-lists.index', 'reports.index'] as $route) {
            $this->get(route($route))->assertInertia(fn ($page) => $page
                ->where('branchScope', null));
        }
    }

    public function test_the_cash_boxes_and_their_entries_name_the_branch_for_whoever_has_several(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        [, $south] = Branch::factory()->count(2)->create();

        $this->actingAs($superAdmin);
        foreach (['payments.cash-boxes', 'payments.cash-boxes.entries'] as $route) {
            $this->get(route($route, ['branch_id' => $south->id]))->assertInertia(fn ($page) => $page
                ->where('branch.id', $south->id)
                ->where('branchScope.level', 'branch')
                ->where('branchScope.name', $south->name));
        }
    }

    public function test_the_cash_boxes_of_someone_with_one_branch_have_no_banner(): void
    {
        $manager = User::factory()->branchAdmin()->create();

        $this->actingAs($manager)->get(route('payments.cash-boxes'))->assertInertia(fn ($page) => $page
            ->where('branchScope', null));
    }

    /**
     * @param  array<int, Branch>  $branches
     * @param  array<int, string>  $actions
     */
    private function auditorOf(array $branches, array $actions): User
    {
        $auditor = User::factory()->financialAuditor()->create();
        $auditor->forceFill(['audit_settings' => ['scope' => 'selected', 'branch_ids' => array_map(fn (Branch $branch) => $branch->id, $branches), 'actions' => $actions]])->save();

        return $auditor;
    }
}
