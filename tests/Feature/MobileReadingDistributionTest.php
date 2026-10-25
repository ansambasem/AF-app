<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Enums\ReadingAssignmentStatus;
use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\MobileAccessToken;
use App\Models\Permission;
use App\Models\ReadingAssignment;
use App\Models\ReadingCycle;
use App\Models\ReadingDistribution;
use App\Models\ReadingEntrySetting;
use App\Models\SubArea;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileReadingDistributionTest extends TestCase
{
    /** @var array<int, string> */
    private array $deviceTokens = [];

    use RefreshDatabase;

    private Branch $branch;

    private User $manager;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-21 09:00', 'Asia/Gaza'));
        $this->branch = Branch::factory()->create();
        ReadingEntrySetting::factory()->forBranch($this->branch)->create(['open_days' => [4], 'reading_day' => 4, 'assignment_lead_days' => 1]);
        $this->manager = User::factory()->branchAdmin()->create(['branch_id' => $this->branch->id]);
        $this->reader = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]);
    }

    private function mobile(User $user): static
    {
        return $this->withHeader('Authorization', 'Bearer '.($this->deviceTokens[$user->id] ??= MobileAccessToken::issue($user)));
    }

    private function box(?Branch $branch = null): MeterBox
    {
        $branch ??= $this->branch;
        $box = MeterBox::factory()->create(['branch_id' => $branch->id, 'sub_area_id' => SubArea::factory()->create()->id]);
        Subscription::factory()->create(['branch_id' => $branch->id, 'meter_box_id' => $box->id, 'initial_reading' => 100, 'circuit_breaker_id' => null]);

        return $box;
    }

    private function cycle(): ReadingCycle
    {
        return ReadingCycle::factory()->create([
            'branch_id' => $this->branch->id, 'created_by' => $this->manager->id,
            'week_start' => '2026-10-16', 'week_end' => '2026-10-22', 'reading_date' => '2026-10-22',
        ]);
    }

    public function test_mobile_distribution_publishes_standing_work_and_readers_get_only_their_own_boxes_and_areas(): void
    {
        $box = $this->box();
        $otherBox = $this->box();
        $this->box(Branch::factory()->create());
        $otherReader = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]);
        $board = $this->mobile($this->manager)->getJson(route('mobile.reading-distribution.index'))
            ->assertOk()->assertJsonPath('abilities.manage', true)->assertJsonPath('abilities.approve', true)->assertJsonCount(2, 'boxes');
        $cycleId = $board->json('cycle.id');
        $this->postJson(route('mobile.reading-distribution.store', $cycleId), [
            'box_ids' => [$box->id], 'versions' => [$box->id => 0], 'target' => 'mobile', 'user_id' => $this->reader->id, 'recurring' => true,
        ])->assertOk()->assertJsonPath('moved', 1)->assertJsonCount(0, 'conflicts');
        $this->postJson(route('mobile.reading-distribution.store', $cycleId), [
            'box_ids' => [$otherBox->id], 'target' => 'mobile', 'user_id' => $otherReader->id, 'publish' => true,
        ])->assertOk();
        $this->assertDatabaseHas('reading_distributions', ['meter_box_id' => $box->id, 'user_id' => $this->reader->id]);
        $work = $this->mobile($this->reader)->getJson(route('mobile.reading-assignments.index'))
            ->assertOk()->assertJsonCount(1, 'assignments')->assertJsonPath('assignments.0.meter_box.id', $box->id)
            ->assertJsonPath('assignments.0.meter_box.sub_area_id', $box->sub_area_id)
            ->assertJsonPath('assignments.0.meter_box.sub_area_name', $box->subArea->name)
            ->assertJsonPath('assignments.0.cycle.entry_open', false)
            ->assertJsonPath('assignments.0.subscriptions.0.previous_reading', 100);
        $this->assertStringStartsWith('2026-10-22T00:00:00', $work->json('assignments.0.cycle.entry_starts_at'));
        $this->assertNotEmpty($work->json('assignments.0.authorization'));
        $this->mobile($this->reader)->getJson(route('mobile.reading-distribution.index'))->assertForbidden();
    }

    public function test_manage_and_approve_permissions_are_separate_and_drafts_stay_hidden_from_readers(): void
    {
        $box = $this->box();
        $distributor = User::factory()->create(['branch_id' => $this->branch->id]);
        $distributor->permissions()->sync(Permission::idsFor([PermissionKey::ManageReadingAssignments]));
        $approver = User::factory()->create(['branch_id' => $this->branch->id]);
        $approver->permissions()->sync(Permission::idsFor([PermissionKey::ApproveReadingAssignments]));
        $this->mobile($distributor)->getJson(route('mobile.me'))->assertOk()
            ->assertJsonPath('user.can_distribute_readings', true)->assertJsonPath('user.can_approve_reading_assignments', false);
        $board = $this->getJson(route('mobile.reading-distribution.index'))->assertOk()->assertJsonPath('abilities.approve', false);
        $cycleId = $board->json('cycle.id');
        $payload = ['box_ids' => [$box->id], 'target' => 'mobile', 'user_id' => $this->reader->id];
        $this->postJson(route('mobile.reading-distribution.store', $cycleId), $payload + ['recurring' => true])->assertForbidden();
        $this->postJson(route('mobile.reading-distribution.store', $cycleId), $payload + ['publish' => true])->assertForbidden();
        $this->postJson(route('mobile.reading-distribution.store', $cycleId), $payload)->assertOk()->assertJsonPath('moved', 1);
        $this->postJson(route('mobile.reading-distribution.approve', $cycleId))->assertForbidden();
        $this->mobile($this->reader)->getJson(route('mobile.reading-assignments.index'))->assertJsonCount(0, 'assignments');
        $this->mobile($approver)->getJson(route('mobile.reading-distribution.index'))->assertOk()->assertJsonPath('abilities.manage', false);
        $this->postJson(route('mobile.reading-distribution.store', $cycleId), $payload)->assertForbidden();
        $this->postJson(route('mobile.reading-distribution.approve', $cycleId))->assertOk()->assertJsonPath('approved', 1);
        $this->mobile($this->reader)->getJson(route('mobile.reading-assignments.index'))->assertJsonCount(1, 'assignments');
    }

    public function test_distribution_permissions_do_not_allow_another_branch(): void
    {
        $foreign = Branch::factory()->create();
        $cycle = ReadingCycle::factory()->create(['branch_id' => $foreign->id]);
        $box = $this->box($foreign);
        $this->mobile($this->manager)->getJson(route('mobile.reading-distribution.index', ['branch_id' => $foreign->id]))->assertForbidden();
        $this->postJson(route('mobile.reading-distribution.store', $cycle), ['box_ids' => [$box->id], 'target' => 'paper'])->assertForbidden();
        $this->postJson(route('mobile.reading-distribution.approve', $cycle))->assertForbidden();
        $this->assertDatabaseCount('reading_assignments', 0);
    }

    public function test_work_appears_on_the_lead_day_and_excludes_drafts_and_closed_cycles(): void
    {
        $cycle = $this->cycle();
        $assignment = ReadingAssignment::factory()->mobile($this->reader)->approved()->create([
            'branch_id' => $this->branch->id, 'reading_cycle_id' => $cycle->id, 'meter_box_id' => $this->box()->id,
        ]);
        ReadingAssignment::factory()->mobile($this->reader)->create([
            'branch_id' => $this->branch->id, 'reading_cycle_id' => $cycle->id, 'meter_box_id' => $this->box()->id, 'status' => ReadingAssignmentStatus::Draft,
        ]);
        $this->travelTo(Carbon::parse('2026-10-20 10:00', 'Asia/Gaza'));
        $this->mobile($this->reader)->getJson(route('mobile.reading-assignments.index'))->assertJsonCount(0, 'assignments');
        $this->travelTo(Carbon::parse('2026-10-21 00:00', 'Asia/Gaza'));
        $this->getJson(route('mobile.reading-assignments.index'))->assertJsonCount(1, 'assignments');
        $this->assertNotNull($assignment->fresh()->downloaded_at);
        $cycle->update(['closed_at' => now()]);
        $this->getJson(route('mobile.reading-assignments.index'))->assertJsonCount(0, 'assignments');
    }

    public function test_legacy_roster_and_writes_cannot_bypass_reader_assignments(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 10:00', 'Asia/Gaza'));
        $cycle = $this->cycle();
        $ownBox = $this->box();
        $otherBox = $this->box();
        ReadingAssignment::factory()->mobile($this->reader)->approved()->create([
            'branch_id' => $this->branch->id, 'reading_cycle_id' => $cycle->id, 'meter_box_id' => $ownBox->id,
        ]);
        $this->mobile($this->reader)->getJson(route('mobile.subscriptions.index'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.meter_box_id', $ownBox->id);
        $this->postJson(route('mobile.readings.store'), [
            'subscription_id' => $otherBox->subscriptions()->sole()->id, 'week_start' => '2026-10-16',
            'current_reading' => 150, 'mobile_operation_id' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('subscription_id');
        $this->assertDatabaseCount('meter_readings', 0);
    }

    public function test_readers_preview_their_standing_boxes_and_next_day_between_readings_without_downloading_work_early(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 10:00', 'Asia/Gaza'));
        $box = $this->box();
        ReadingDistribution::factory()->create(['meter_box_id' => $box->id, 'user_id' => $this->reader->id, 'approved_by' => $this->manager->id]);
        $subscriber = $box->subscriptions()->firstOrFail();
        $subscriber->update(['subscription_phone' => '0599000001']);
        ReadingDistribution::factory()->create(['meter_box_id' => $this->box()->id]);
        $this->mobile($this->reader)->getJson(route('mobile.reading-assignments.index'))
            ->assertOk()->assertJsonCount(0, 'assignments')->assertJsonPath('next_reading.reading_date', '2026-10-22')
            ->assertJsonCount(1, 'next_reading.boxes')->assertJsonPath('next_reading.boxes.0.id', $box->id)
            ->assertJsonPath('next_reading.boxes.0.sub_area_name', $box->subArea->name)
            ->assertJsonPath('next_reading.boxes.0.subscribers', 1)->assertJsonMissingPath('next_reading.boxes.0.authorization')
            ->assertJsonPath('next_reading.boxes.0.subscriptions.0.id', $subscriber->id)
            ->assertJsonPath('next_reading.boxes.0.subscriptions.0.phone', '0599000001');
        $this->assertDatabaseCount('reading_cycles', 0);
        $this->assertDatabaseCount('reading_assignments', 0);

        $this->travelTo(Carbon::parse('2026-10-21 10:00', 'Asia/Gaza'));
        $this->getJson(route('mobile.reading-assignments.index'))->assertJsonCount(1, 'assignments')
            ->assertJsonPath('assignments.0.cycle.entry_open', false);
        $this->travelTo(Carbon::parse('2026-10-22 10:00', 'Asia/Gaza'));
        $this->getJson(route('mobile.reading-assignments.index'))->assertJsonPath('assignments.0.cycle.entry_open', true);

        $this->travelTo(Carbon::parse('2026-10-26 10:00', 'Asia/Gaza'));
        $this->getJson(route('mobile.reading-assignments.index'))->assertJsonCount(0, 'assignments')
            ->assertJsonPath('next_reading.reading_date', '2026-10-29')->assertJsonPath('next_reading.boxes.0.id', $box->id);
    }

    public function test_preview_uses_the_delayed_cycle_date_and_respects_reassignments_and_drafts_over_the_standing_plan(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 10:00', 'Asia/Gaza'));
        $cycle = $this->cycle();
        $cycle->update(['reading_date' => '2026-10-24']);
        $otherReader = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]);
        $reassigned = $this->box();
        ReadingDistribution::factory()->create(['meter_box_id' => $reassigned->id, 'user_id' => $this->reader->id, 'approved_by' => $this->manager->id]);
        ReadingAssignment::factory()->mobile($otherReader)->approved()->create([
            'reading_cycle_id' => $cycle->id, 'branch_id' => $this->branch->id, 'meter_box_id' => $reassigned->id,
        ]);
        $assigned = ReadingAssignment::factory()->mobile($this->reader)->approved()->create([
            'reading_cycle_id' => $cycle->id, 'branch_id' => $this->branch->id, 'meter_box_id' => $this->box()->id,
        ]);
        ReadingAssignment::factory()->mobile($this->reader)->create([
            'reading_cycle_id' => $cycle->id, 'branch_id' => $this->branch->id, 'meter_box_id' => $this->box()->id,
        ]);
        $this->mobile($this->reader)->getJson(route('mobile.reading-assignments.index'))
            ->assertOk()->assertJsonCount(0, 'assignments')->assertJsonPath('next_reading.reading_date', '2026-10-24')
            ->assertJsonCount(1, 'next_reading.boxes')->assertJsonPath('next_reading.boxes.0.id', $assigned->meter_box_id);
        $this->assertNull($assigned->fresh()->downloaded_at);
    }

    public function test_reassigning_downloaded_boxes_requires_confirmation_and_checks_versions(): void
    {
        $cycle = $this->cycle();
        $box = $this->box();
        $assignment = ReadingAssignment::factory()->mobile($this->reader)->approved()->create([
            'branch_id' => $this->branch->id, 'reading_cycle_id' => $cycle->id, 'meter_box_id' => $box->id,
            'version' => 1, 'downloaded_at' => now(), 'downloaded_version' => 1,
        ]);
        $payload = ['box_ids' => [$box->id], 'versions' => [$box->id => 1], 'target' => 'paper', 'publish' => true];
        $this->mobile($this->manager)->postJson(route('mobile.reading-distribution.store', $cycle), $payload)
            ->assertOk()->assertJsonPath('moved', 0)->assertJsonPath('confirmation_box_ids.0', $box->id);
        $this->postJson(route('mobile.reading-distribution.store', $cycle), $payload + ['confirm_downloaded' => true])
            ->assertOk()->assertJsonPath('moved', 1)->assertJsonCount(0, 'conflicts');
        $this->postJson(route('mobile.reading-distribution.store', $cycle), $payload + ['confirm_downloaded' => true])
            ->assertOk()->assertJsonPath('moved', 0)->assertJsonCount(1, 'conflicts')->assertJsonCount(0, 'confirmation_box_ids');
        $this->assertSame(2, $assignment->fresh()->version);
    }
}
