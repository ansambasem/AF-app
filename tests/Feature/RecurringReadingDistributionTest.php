<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Enums\ReadingAssignmentStatus;
use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\Permission;
use App\Models\ReadingCycle;
use App\Models\ReadingDistribution;
use App\Models\ReadingEntrySetting;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecurringReadingDistributionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Branch, User, User, MeterBox, ReadingCycle} */
    private function work(): array
    {
        $this->travelTo('2026-10-20 09:00:00');
        $branch = Branch::factory()->create();
        $admin = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);
        $reader = User::factory()->dataEntry()->create(['branch_id' => $branch->id]);
        $box = MeterBox::factory()->create(['branch_id' => $branch->id]);
        Subscription::factory()->create(['branch_id' => $branch->id, 'meter_box_id' => $box->id, 'registered_by' => $admin->id]);
        $cycle = ReadingCycle::factory()->create([
            'branch_id' => $branch->id, 'created_by' => $admin->id,
            'week_start' => '2026-10-16', 'week_end' => '2026-10-22', 'reading_date' => '2026-10-22',
        ]);

        return [$branch, $admin, $reader, $box, $cycle];
    }

    /** @return array<string, mixed> */
    private function allocation(MeterBox $box, User $reader, bool $recurring = true): array
    {
        return ['box_ids' => [$box->id], 'target' => 'mobile', 'user_id' => $reader->id, 'recurring' => $recurring, 'publish' => true];
    }

    public function test_standing_distribution_repeats_weekly_without_duplicate_assignments(): void
    {
        [$branch, $admin, $reader, $box, $cycle] = $this->work();

        $this->actingAs($admin)->post(route('reading-cycles.assignments.store', $cycle), $this->allocation($box, $reader))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('reading_distributions', ['meter_box_id' => $box->id, 'user_id' => $reader->id, 'approved_by' => $admin->id]);
        $this->assertSame(ReadingAssignmentStatus::Approved, $cycle->assignments()->sole()->status);
        $this->travelTo('2026-10-28 09:00:00');
        $this->artisan('readings:prepare-distributions')->assertSuccessful();
        $next = ReadingCycle::whereDate('week_start', '2026-10-23')->sole();
        $this->assertSame($reader->id, $next->assignments()->sole()->user_id);
        $this->assertSame(ReadingAssignmentStatus::Approved, $next->assignments()->sole()->status);
        $this->artisan('readings:prepare-distributions')->assertSuccessful();
        $this->assertSame(1, $next->assignments()->count());
        $this->assertSame(2, $next->assignments()->sole()->events()->count());
    }

    public function test_a_one_week_replacement_preserves_the_standing_reader_and_survives_preparation(): void
    {
        [$branch, $admin, $reader, $box, $cycle] = $this->work();
        $replacement = User::factory()->dataEntry()->create(['branch_id' => $branch->id]);
        $this->actingAs($admin)->post(route('reading-cycles.assignments.store', $cycle), $this->allocation($box, $reader))->assertSessionHasNoErrors();

        $this->post(route('reading-cycles.assignments.store', $cycle), $this->allocation($box, $replacement, false))->assertSessionHasNoErrors();

        $this->assertSame($reader->id, ReadingDistribution::sole()->user_id);
        $this->travelTo('2026-10-21 09:00:00');
        $this->artisan('readings:prepare-distributions')->assertSuccessful();
        $this->assertSame($replacement->id, $cycle->assignments()->sole()->user_id);
        $this->travelTo('2026-10-28 09:00:00');
        $this->artisan('readings:prepare-distributions')->assertSuccessful();
        $this->assertSame($reader->id, ReadingCycle::whereDate('week_start', '2026-10-23')->sole()->assignments()->sole()->user_id);
    }

    public function test_preparation_obeys_the_branch_day_and_lead_time(): void
    {
        [$branch, $admin, $reader, $box, $cycle] = $this->work();
        ReadingEntrySetting::factory()->forBranch($branch)->create(['reading_day' => 6, 'assignment_lead_days' => 2]);
        $cycle->update(['closed_at' => now(), 'closed_by' => $admin->id]);
        ReadingDistribution::factory()->create(['branch_id' => $branch->id, 'meter_box_id' => $box->id, 'user_id' => $reader->id, 'approved_by' => $admin->id]);

        $this->travelTo('2026-10-21 09:00:00');
        $this->artisan('readings:prepare-distributions')->assertSuccessful();
        $this->assertSame(0, ReadingCycle::where('branch_id', $branch->id)->whereDate('reading_date', '2026-10-24')->count());
        $this->travelTo(Carbon::parse('2026-10-22 00:00:00', 'Asia/Gaza'));
        $this->artisan('readings:prepare-distributions')->assertSuccessful();

        $this->assertSame(1, ReadingCycle::where('branch_id', $branch->id)->whereDate('reading_date', '2026-10-24')->count());
    }

    public function test_inactive_readers_and_readers_without_permission_are_left_unassigned(): void
    {
        [$branch, $admin, $reader, $box, $cycle] = $this->work();
        $this->actingAs($admin)->post(route('reading-cycles.assignments.store', $cycle), $this->allocation($box, $reader))->assertSessionHasNoErrors();
        $reader->update(['is_active' => false]);
        $this->travelTo('2026-10-28 09:00:00');

        $this->artisan('readings:prepare-distributions')->assertSuccessful();

        $this->assertSame(0, ReadingCycle::whereDate('week_start', '2026-10-23')->sole()->assignments()->count());
        $reader->update(['is_active' => true]);
        $reader->permissions()->sync([]);
        $this->travelTo('2026-11-04 09:00:00');
        $this->artisan('readings:prepare-distributions')->assertSuccessful();
        $this->assertSame(0, ReadingCycle::whereDate('week_start', '2026-10-30')->sole()->assignments()->count());
    }

    public function test_a_manager_without_approval_permission_cannot_save_standing_distributions(): void
    {
        [$branch, $admin, $reader, $box, $cycle] = $this->work();
        $manager = User::factory()->dataEntry()->create(['branch_id' => $branch->id]);
        $manager->permissions()->sync(Permission::idsFor([PermissionKey::ManageReadingAssignments]));

        $this->actingAs($manager)->post(route('reading-cycles.assignments.store', $cycle), $this->allocation($box, $reader))->assertForbidden();

        $this->assertDatabaseCount('reading_distributions', 0);
        $this->assertDatabaseCount('reading_assignments', 0);
    }

    public function test_failed_moves_do_not_change_the_standing_distribution(): void
    {
        [$branch, $admin, $reader, $box, $cycle] = $this->work();
        $this->actingAs($admin)->post(route('reading-cycles.assignments.store', $cycle), $this->allocation($box, $reader))->assertSessionHasNoErrors();
        $replacement = User::factory()->dataEntry()->create(['branch_id' => $branch->id]);

        $this->post(route('reading-cycles.assignments.store', $cycle), [...$this->allocation($box, $replacement), 'versions' => [$box->id => 0]])->assertSessionHasErrors('box_ids');

        $this->assertSame($reader->id, ReadingDistribution::sole()->user_id);
        $this->assertSame($reader->id, $cycle->assignments()->sole()->user_id);
    }

    public function test_existing_assignments_can_be_made_standing_then_removed_permanently(): void
    {
        [$branch, $admin, $reader, $box, $cycle] = $this->work();
        $this->actingAs($admin)->post(route('reading-cycles.assignments.store', $cycle), $this->allocation($box, $reader, false))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('reading_distributions', 0);

        $this->post(route('reading-cycles.assignments.store', $cycle), $this->allocation($box, $reader))->assertSessionHasNoErrors();
        $this->assertSame($reader->id, ReadingDistribution::sole()->user_id);
        $this->post(route('reading-cycles.assignments.store', $cycle), ['box_ids' => [$box->id], 'target' => 'available', 'recurring' => true])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('reading_distributions', 0);
        $this->assertSame(ReadingAssignmentStatus::Unassigned, $cycle->assignments()->sole()->status);
    }

    public function test_preparing_the_upcoming_distribution_is_idempotent_and_branch_scoped(): void
    {
        [$branch, $admin, $reader, $box, $cycle] = $this->work();

        $this->actingAs($admin)->post(route('reading-cycles.prepare'), ['branch_id' => $branch->id])->assertRedirect(route('reading-cycles.show', $cycle));
        $this->post(route('reading-cycles.prepare'), ['branch_id' => $branch->id])->assertRedirect(route('reading-cycles.show', $cycle));
        $this->post(route('reading-cycles.prepare'), ['branch_id' => Branch::factory()->create()->id])->assertForbidden();

        $this->assertDatabaseCount('reading_cycles', 1);
    }

    public function test_lead_time_is_saved_validated_and_shown_on_the_distribution(): void
    {
        [$branch, $admin, $reader, $box, $cycle] = $this->work();
        $settings = ['branch_id' => $branch->id, 'reading_day' => 4, 'open_days' => [4], 'mode' => 'automatic', 'assignment_lead_days' => 2];

        $this->actingAs($admin)->put(route('settings.reading-schedule.update'), $settings)->assertSessionHasNoErrors();

        $this->assertSame(2, ReadingEntrySetting::ownFor($branch)->assignment_lead_days);
        $this->put(route('settings.reading-schedule.update'), [...$settings, 'assignment_lead_days' => 7])->assertSessionHasErrors('assignment_lead_days');
        $this->get(route('reading-cycles.show', $cycle))->assertInertia(fn ($page) => $page->where('cycle.availableOn', '2026-10-20'));
    }

    public function test_paper_distributions_repeat_and_produce_printable_sheets_without_a_phone(): void
    {
        [$branch, $admin, $reader, $box, $cycle] = $this->work();
        $this->actingAs($admin)->post(route('reading-cycles.assignments.store', $cycle), ['box_ids' => [$box->id], 'target' => 'paper', 'recurring' => true])->assertSessionHasNoErrors();
        $this->travelTo('2026-10-28 09:00:00');
        $this->artisan('readings:prepare-distributions')->assertSuccessful();
        $next = ReadingCycle::whereDate('week_start', '2026-10-23')->sole();

        $this->post(route('reading-cycles.paper-sheets.store', $next), ['box_ids' => [$box->id]])->assertSessionHasNoErrors();

        $sheet = $next->paperSheets()->sole();
        $this->assertNull($next->assignments()->sole()->user_id);
        $this->get(route('paper-sheets.show', $sheet))->assertInertia(fn ($page) => $page->where('boxes.0.id', $box->id));
    }

    public function test_delaying_this_weeks_reading_also_delays_automatic_preparation(): void
    {
        [$branch, $admin, $reader, $box, $cycle] = $this->work();
        $cycle->update(['reading_date' => '2026-10-23']);
        ReadingDistribution::factory()->create(['branch_id' => $branch->id, 'meter_box_id' => $box->id, 'user_id' => $reader->id, 'approved_by' => $admin->id]);
        $this->travelTo('2026-10-21 09:00:00');

        $this->artisan('readings:prepare-distributions')->assertSuccessful();

        $this->assertSame(0, $cycle->assignments()->count());
        $this->travelTo('2026-10-22 09:00:00');
        $this->artisan('readings:prepare-distributions')->assertSuccessful();
        $this->assertSame($reader->id, $cycle->assignments()->sole()->user_id);
    }

    public function test_a_standing_distribution_cannot_be_assigned_to_another_branchs_reader(): void
    {
        [$branch, $admin, $reader, $box, $cycle] = $this->work();
        $otherReader = User::factory()->dataEntry()->create();

        $this->actingAs($admin)->post(route('reading-cycles.assignments.store', $cycle), $this->allocation($box, $otherReader))->assertSessionHasErrors('box_ids');

        $this->assertDatabaseCount('reading_distributions', 0);
        $this->assertDatabaseCount('reading_assignments', 0);
    }

    public function test_a_delayed_cycle_can_be_prepared_after_the_next_reading_week_has_started(): void
    {
        [$branch, $admin, $reader, $box, $cycle] = $this->work();
        $cycle->update(['reading_date' => '2026-10-24']);
        ReadingDistribution::factory()->create(['branch_id' => $branch->id, 'meter_box_id' => $box->id, 'user_id' => $reader->id, 'approved_by' => $admin->id]);
        $this->travelTo(Carbon::parse('2026-10-23 00:00:00', 'Asia/Gaza'));

        $this->artisan('readings:prepare-distributions')->assertSuccessful();

        $this->assertSame($reader->id, $cycle->assignments()->sole()->user_id);
        $this->assertDatabaseCount('reading_cycles', 1);
    }

    public function test_branch_staff_open_the_upcoming_distribution_without_branch_cards_or_history(): void
    {
        [$branch, $admin, $reader, $box, $cycle] = $this->work();
        ReadingCycle::factory()->create([
            'branch_id' => $branch->id, 'created_by' => $admin->id,
            'week_start' => '2026-10-09', 'week_end' => '2026-10-15', 'reading_date' => '2026-10-15',
        ]);

        $this->actingAs($admin)->get(route('reading-cycles.index'))
            ->assertInertia(fn ($page) => $page->component('ReadingCycles/Show')
                ->where('cycle.id', $cycle->id)->where('showBranchSelector', false)
                ->missing('distributionBranches')->missing('cycles')->missing('weekOptions'));
    }

    public function test_the_next_friday_keeps_the_standing_distribution_after_the_reading_day_passes(): void
    {
        [$branch, $admin, $reader, $box, $cycle] = $this->work();
        ReadingEntrySetting::factory()->forBranch($branch)->create(['reading_day' => 5]);
        $cycle->update(['week_start' => '2026-10-17', 'week_end' => '2026-10-23', 'reading_date' => '2026-10-23']);
        $this->actingAs($admin)->post(route('reading-cycles.assignments.store', $cycle), $this->allocation($box, $reader))->assertSessionHasNoErrors();
        $this->travelTo('2026-10-24 09:00:00');

        $this->get(route('reading-cycles.index'))->assertInertia(fn ($page) => $page
            ->component('ReadingCycles/Show')->where('cycle.readingDate', '2026-10-30')
            ->where('boards.0.column', 'reader:'.$reader->id)->where('showBranchSelector', false));

        $this->assertSame($reader->id, $cycle->assignments()->sole()->user_id);
        $this->assertSame($reader->id, ReadingDistribution::sole()->user_id);
        $this->assertDatabaseCount('reading_cycles', 2);
    }

    public function test_the_central_super_admin_sees_only_upcoming_branch_choices(): void
    {
        [$branch, $admin, $reader, $box, $cycle] = $this->work();
        $superAdmin = User::factory()->superAdmin()->create(['branch_id' => null]);

        $this->actingAs($superAdmin)->get(route('reading-cycles.index'))
            ->assertInertia(fn ($page) => $page->component('ReadingCycles/Index')
                ->where('branch', null)->has('distributionBranches', 1)
                ->where('distributionBranches.0.cycleId', $cycle->id)
                ->where('distributionBranches.0.readingDate', '2026-10-22')
                ->missing('cycles')->missing('weekOptions'));
    }

    public function test_a_super_admin_inside_a_branch_domain_also_opens_that_branch_directly(): void
    {
        [$branch, $admin, $reader, $box, $cycle] = $this->work();
        config(['app.url' => 'https://admin.company.test', 'powercollect.branch_domains_enabled' => true]);
        $branch->forceFill(['domain' => 'north.company.test', 'domain_enabled' => true])->save();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->get('https://north.company.test/reading-cycles')
            ->assertInertia(fn ($page) => $page->component('ReadingCycles/Show')
                ->where('cycle.id', $cycle->id)->where('showBranchSelector', false)->missing('distributionBranches'));
    }

    public function test_closing_this_reading_moves_the_branch_view_to_the_next_one(): void
    {
        [$branch, $admin, $reader, $box, $cycle] = $this->work();
        $cycle->update(['closed_at' => now(), 'closed_by' => $admin->id]);
        ReadingDistribution::factory()->create(['branch_id' => $branch->id, 'meter_box_id' => $box->id, 'user_id' => $reader->id, 'approved_by' => $admin->id]);

        $this->actingAs($admin)->get(route('reading-cycles.index'))->assertInertia(fn ($page) => $page
            ->where('cycle.readingDate', '2026-10-29')->where('boards.0.column', 'reader:'.$reader->id));

        $this->assertDatabaseCount('reading_cycles', 2);
        $this->assertTrue($cycle->fresh()->isClosed());
    }

    public function test_staff_with_view_permission_do_not_see_branch_choices_or_prepare_new_readings(): void
    {
        [$branch, $admin, $reader, $box, $cycle] = $this->work();
        $reader->permissions()->sync(Permission::idsFor([PermissionKey::ViewReadingAssignments]));
        $this->travelTo('2026-10-24 09:00:00');

        $this->actingAs($reader)->get(route('reading-cycles.index'))->assertInertia(fn ($page) => $page
            ->component('ReadingCycles/Index')->where('distributionBranches', [])
            ->where('branch.readingDate', '2026-10-29')->where('canCreate', false)->missing('cycles'));

        $this->assertDatabaseCount('reading_cycles', 1);
    }
}
