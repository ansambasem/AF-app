<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Closing;
use App\Models\ClosingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ClosedBoxPeriodsTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith([0])]
    #[TestWith([1])]
    #[TestWith([2])]
    #[TestWith([3])]
    #[TestWith([4])]
    #[TestWith([5])]
    #[TestWith([6])]
    public function test_company_admin_can_choose_any_week_start_day(int $day): void
    {
        $admin = User::factory()->superAdmin()->create();
        $box = CashBox::factory()->create(['status' => 'received', 'closed_at' => now()->subMonth(), 'sent_at' => now()->subWeek()]);
        $original = $box->fresh()->getAttributes();

        $this->actingAs($admin)->put(route('settings.payments.update'), ['week_starts_on' => $day, 'cutoff_time' => '18:00'])
            ->assertRedirect(route('settings.payments.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('closing_settings', ['branch_id' => null, 'week_starts_on' => $day, 'cutoff_time' => '00:00', 'updated_by' => $admin->id]);
        $this->assertSame($original, $box->fresh()->getAttributes());
        $this->get(route('settings.payments.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('boxPeriod.week_starts_on', $day));
    }

    #[TestWith([-1])]
    #[TestWith([7])]
    #[TestWith([1.5])]
    #[TestWith(['Monday'])]
    #[TestWith([null])]
    public function test_invalid_start_days_do_not_change_the_setting(mixed $day): void
    {
        ClosingSetting::company()->update(['week_starts_on' => 6]);

        $this->actingAs(User::factory()->superAdmin()->create())->put(route('settings.payments.update'), ['week_starts_on' => $day])
            ->assertSessionHasErrors('week_starts_on');

        $this->assertDatabaseHas('closing_settings', ['branch_id' => null, 'week_starts_on' => 6]);
    }

    public function test_only_company_admins_and_authorized_branch_admins_can_access_period_settings(): void
    {
        ClosingSetting::company();
        $this->put(route('settings.payments.update'), ['week_starts_on' => 1])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->branchAdmin()->create())->put(route('settings.payments.update'), ['week_starts_on' => 1])->assertForbidden();
        $this->actingAs(User::factory()->financialAuditor()->create())->put(route('settings.payments.update'), ['week_starts_on' => 1])->assertForbidden();

        $this->assertDatabaseHas('closing_settings', ['branch_id' => null, 'week_starts_on' => 6]);
    }

    private function closedBox(Branch $branch, string $closedAt, array $attributes = []): CashBox
    {
        return CashBox::factory()->create([
            'branch_id' => $branch->id, 'closing_id' => Closing::factory()->for($branch),
            'status' => 'received', 'opened_at' => '2026-09-01 10:00:00', 'closed_at' => $closedAt,
            ...$attributes,
        ]);
    }

    public function test_auditors_see_closed_boxes_automatically_in_business_timezone_weeks_including_previously_sent_boxes(): void
    {
        ClosingSetting::company()->update(['week_starts_on' => 6]);
        $branch = Branch::factory()->create();
        $auditor = User::factory()->financialAuditor()->create();
        $before = $this->closedBox($branch, '2026-10-16 20:59:59');
        $first = $this->closedBox($branch, '2026-10-16 21:00:00');
        $sent = $this->closedBox($branch, '2026-10-23 20:59:59', ['sent_at' => '2026-10-24 10:00:00', 'send_note' => 'سجل سابق']);
        $after = $this->closedBox($branch, '2026-10-23 21:00:00');
        $this->closedBox(Branch::factory()->create(), '2026-10-18 10:00:00');
        CashBox::factory()->create(['branch_id' => $branch->id]);
        $original = $sent->fresh()->getAttributes();

        $this->actingAs($auditor)->get(route('payments.cash-boxes', ['branch_id' => $branch->id, 'week' => '2026-10-17']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Payments/BranchCashBoxes')->where('started', true)
                ->where('week', '2026-10-17')->has('tabs', 1)->where('tabs.0.key', 'closed')->where('tabs.0.count', 4)
                ->has('boxes', 2)->where('boxes', fn ($boxes): bool => collect($boxes)->pluck('id')->sort()->values()->all() === [$first->id, $sent->id])
                ->where('boxes.0.canCount', false)->where('boxes.0.place', 'closed')->where('access.receive', false)
                ->has('periods', 3)->where('periods.1.start', '2026-10-17')->where('periods.1.end', '2026-10-23')->where('periods.1.count', 2));

        $this->assertSame($original, $sent->fresh()->getAttributes());
    }

    public function test_changing_the_start_day_regroups_the_view_without_modifying_closed_boxes(): void
    {
        $branch = Branch::factory()->create();
        $auditor = User::factory()->financialAuditor()->create();
        ClosingSetting::company()->update(['week_starts_on' => 1]);
        $sunday = $this->closedBox($branch, '2026-10-18 12:00:00');
        $monday = $this->closedBox($branch, '2026-10-19 12:00:00');

        $this->actingAs($auditor)->get(route('payments.cash-boxes', ['branch_id' => $branch->id, 'week' => '2026-10-20']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('week', '2026-10-19')->has('boxes', 1)->where('boxes.0.id', $monday->id));
        $this->get(route('payments.cash-boxes', ['branch_id' => $branch->id, 'week' => '2026-10-12']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('boxes', 1)->where('boxes.0.id', $sunday->id));
    }

    public function test_a_link_to_a_historical_box_selects_its_actual_confirmation_week_without_mixing_other_weeks(): void
    {
        $branch = Branch::factory()->create();
        $auditor = User::factory()->financialAuditor()->create();
        $linked = $this->closedBox($branch, '2026-10-10 12:00:00', ['received_at' => '2026-10-19 12:00:00']);
        $peer = $this->closedBox($branch, '2026-10-20 12:00:00');
        $this->closedBox($branch, '2026-10-14 12:00:00');

        $this->actingAs($auditor)->get(route('payments.cash-boxes', ['branch_id' => $branch->id, 'week' => '2026-10-10', 'box' => $linked->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('week', '2026-10-17')->has('boxes', 2)
                ->where('boxes', fn ($boxes): bool => collect($boxes)->pluck('id')->sort()->values()->all() === [$linked->id, $peer->id]));
    }

    public function test_inactive_auditors_cannot_browse_closed_boxes(): void
    {
        $auditor = User::factory()->financialAuditor()->create(['is_active' => false]);

        $this->actingAs($auditor)->get(route('payments.cash-boxes'))->assertRedirect(route('login'));
    }

    public function test_an_auditor_cannot_expose_an_open_box_or_close_it(): void
    {
        $auditor = User::factory()->financialAuditor()->create();
        $branch = Branch::factory()->create();
        $open = CashBox::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($auditor)->get(route('payments.cash-boxes', ['branch_id' => $branch->id, 'place' => 'open', 'box' => $open->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('place', 'closed')->has('boxes', 0));
        $this->get(route('payments.cash-boxes.entries', ['branch_id' => $branch->id]))->assertForbidden();
        $this->post(route('payments.cash-boxes.actions', ['action' => 'receive', 'record' => $open->id]), ['branch_id' => $branch->id])->assertNotFound();

        $this->assertDatabaseHas('cash_boxes', ['id' => $open->id, 'status' => 'open']);
    }

    public function test_a_branch_manager_cannot_browse_another_branchs_closed_boxes(): void
    {
        $manager = User::factory()->branchAdmin()->create();
        $own = $this->closedBox($manager->branch, '2026-10-18 12:00:00');
        $other = $this->closedBox(Branch::factory()->create(), '2026-10-18 12:00:00');

        $this->actingAs($manager)->get(route('payments.cash-boxes', ['branch_id' => $other->branch_id, 'box' => $other->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('branch.id', $manager->branch_id)->has('branches', 1)->has('boxes', 1)->where('boxes.0.id', $own->id));
    }

    public function test_periods_span_year_boundaries_and_empty_weeks_remain_empty(): void
    {
        $auditor = User::factory()->financialAuditor()->create();
        $branch = Branch::factory()->create();
        ClosingSetting::company()->update(['week_starts_on' => 1]);
        $box = $this->closedBox($branch, '2027-01-01 12:00:00');

        $this->actingAs($auditor)->get(route('payments.cash-boxes', ['branch_id' => $branch->id, 'week' => '2027-01-01']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('week', '2026-12-28')->where('periods.0.end', '2027-01-03')->where('boxes.0.id', $box->id));
        $this->get(route('payments.cash-boxes', ['branch_id' => $branch->id, 'week' => '2027-02-01']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('boxes', 0)->where('week', '2027-02-01'));
        $this->get(route('payments.cash-boxes', ['branch_id' => $branch->id, 'week' => 'invalid']))->assertSessionHasErrors('week');
    }
}
