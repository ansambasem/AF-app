<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashBoxPayment;
use App\Models\MeterBox;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BranchClosingWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultBranchScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_start_with_every_visible_branch_and_an_explicit_choice_can_be_cleared(): void
    {
        [$first, $second] = Branch::factory()->count(2)->create();
        $this->box($first);
        $this->box($second);
        $owner = User::factory()->superAdmin()->create();

        $this->actingAs($owner)->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('filters.branch', 'all')->where('branchScope.name', 'جميع الفروع')
            ->where('collections.total', '200.00')->has('branchSummary', 2));
        $this->get(route('reports.index', ['branch' => (string) $second->id]))->assertInertia(fn ($page) => $page
            ->where('filters.branch', $second->id)->where('collections.total', '100.00'));
        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page->where('filters.branch', 'all'));
    }

    public function test_cash_boxes_start_with_every_branch_and_can_return_to_all_after_choosing_one(): void
    {
        [$first, $second] = Branch::factory()->count(2)->create();
        $firstBox = $this->box($first, closed: true);
        $secondBox = $this->box($second, closed: true);
        $owner = User::factory()->superAdmin()->create();

        $this->actingAs($owner)->get(route('payments.cash-boxes'))->assertInertia(fn ($page) => $page
            ->where('branch', null)->where('branchScope.name', 'جميع الفروع')
            ->where('boxes', fn ($boxes): bool => collect($boxes)->pluck('id')->sort()->values()->all() === [$firstBox->id, $secondBox->id])
            ->where('canApprovePeriod', false));
        $this->get(route('payments.cash-boxes', ['branch_id' => $first->id]))->assertInertia(fn ($page) => $page
            ->where('branch.id', $first->id)->has('boxes', 1));
        $this->get(route('payments.cash-boxes', ['branch_id' => 'all']))->assertInertia(fn ($page) => $page
            ->where('branch', null)->has('boxes', 2));
    }

    public function test_open_entries_include_all_visible_branches_and_link_to_the_correct_box_branch(): void
    {
        [$first, $second] = Branch::factory()->count(2)->create();
        $firstBox = $this->box($first);
        $secondBox = $this->box($second);
        $owner = User::factory()->superAdmin()->create();

        $this->actingAs($owner)->get(route('payments.cash-boxes.entries'))->assertInertia(fn ($page) => $page
            ->where('branch', null)->where('branchScope.name', 'جميع الفروع')
            ->where('entries.total', 2)->where('closableBoxes', null)
            ->where('entries.data', fn ($entries): bool => collect($entries)->pluck('box.url')->sort()->values()->all() === collect([
                route('payments.cash-boxes', ['branch_id' => $first->id, 'box' => $firstBox->id]),
                route('payments.cash-boxes', ['branch_id' => $second->id, 'box' => $secondBox->id]),
            ])->sort()->values()->all()));
        $this->get(route('payments.cash-boxes.entries', ['branch_id' => $second->id]))->assertInertia(fn ($page) => $page
            ->where('branch.id', $second->id)->where('entries.total', 1));
    }

    public function test_the_all_branch_cash_box_view_respects_an_auditors_allowed_branches(): void
    {
        [$first, $second, $private] = Branch::factory()->count(3)->create();
        $this->box($first, closed: true);
        $this->box($second, closed: true);
        $this->box($private, closed: true);
        $auditor = User::factory()->financialAuditor()->for($first)->create(['audit_settings' => [
            'scope' => 'selected', 'branch_ids' => [$first->id, $second->id], 'actions' => ['view_boxes'],
        ]]);

        $this->actingAs($auditor)->get(route('payments.cash-boxes'))->assertInertia(fn ($page) => $page
            ->where('branchScope.branches', 2)->has('boxes', 2)
            ->where('boxes', fn ($boxes): bool => ! collect($boxes)->contains('branch_id', $private->id)));
        $this->get(route('payments.cash-boxes', ['branch_id' => $private->id]))->assertNotFound();
        $this->get(route('payments.cash-boxes', ['place' => 'open']))->assertForbidden();
    }

    private function box(Branch $branch, bool $closed = false): CashBox
    {
        $this->travelTo('2026-10-27 12:00:00');
        $manager = User::factory()->branchAdmin()->for($branch)->create();
        $collector = User::factory()->collector()->for($branch)->create();
        app(BranchClosingWorkflow::class)->start($branch->id, $manager);
        $subscription = Subscription::factory()->for($branch)->create([
            'registered_by' => $collector->id, 'meter_box_id' => MeterBox::factory()->for($branch)->create()->id,
        ]);
        $payment = SubscriptionTransaction::recordPayment($subscription, $collector, [
            'amount' => '100', 'currency' => 'ILS', 'payment_method' => 'cash',
        ]);
        $box = CashBoxPayment::query()->where('subscription_transaction_id', $payment->id)->sole()->box;
        if ($closed) {
            $box->update(['status' => 'received', 'closed_at' => now(), 'received_at' => now()]);
        }

        return $box;
    }
}
