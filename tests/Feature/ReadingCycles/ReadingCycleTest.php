<?php

namespace Tests\Feature\ReadingCycles;

use App\Enums\MeterReadingStatus;
use App\Enums\PermissionKey;
use App\Enums\ReadingAssignmentChannel;
use App\Enums\ReadingAssignmentStatus;
use App\Enums\ReadingSubmissionStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\MeterReading;
use App\Models\MobileAccessToken;
use App\Models\PaperSheet;
use App\Models\ReadingAssignment;
use App\Models\ReadingCycle;
use App\Models\ReadingEntrySetting;
use App\Models\ReadingSubmission;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ReadingCycleTest extends TestCase
{
    /** @var array<int, string> */
    private array $deviceTokens = [];

    use RefreshDatabase;

    private Branch $branch;

    private User $branchAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // Tuesday morning; the branch reads on Thursdays.
        $this->travelTo(Carbon::parse('2026-10-20 09:00', 'Asia/Gaza'));
        $this->branch = Branch::factory()->create();
        $this->branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $this->branch->id]);
    }

    private function reader(string $name): User
    {
        return User::factory()->dataEntry()->create(['branch_id' => $this->branch->id, 'name' => $name]);
    }

    /**
     * @return Collection<int, MeterBox>
     */
    private function boxes(int $count): Collection
    {
        return collect(range(1, $count))->map(function (int $number): MeterBox {
            $box = MeterBox::factory()->create(['branch_id' => $this->branch->id, 'box_number' => 'B-'.Str::padLeft((string) $number, 3, '0'), 'name' => 'المدارس', 'name_suffix' => (string) $number]);
            Subscription::factory()->create(['branch_id' => $this->branch->id, 'meter_box_id' => $box->id, 'circuit_breaker_id' => null, 'initial_reading' => 100]);

            return $box;
        });
    }

    private function cycle(array $attributes = []): ReadingCycle
    {
        return ReadingCycle::factory()->create([
            'branch_id' => $this->branch->id,
            'week_start' => '2026-10-16',
            'week_end' => '2026-10-22',
            'reading_date' => '2026-10-22',
            'created_by' => $this->branchAdmin->id,
            ...$attributes,
        ]);
    }

    /**
     * @param  Collection<int, MeterBox>|array<int, MeterBox>  $boxes
     */
    private function allocate(User $actor, ReadingCycle $cycle, iterable $boxes, string $target, ?User $reader = null, array $extra = []): TestResponse
    {
        $boxes = collect($boxes);

        return $this->actingAs($actor)->post(route('reading-cycles.assignments.store', $cycle), [
            'box_ids' => $boxes->pluck('id')->all(),
            'target' => $target,
            'user_id' => $reader?->id,
            ...$extra,
        ]);
    }

    private function mobile(User $reader): static
    {
        return $this->withHeader('Authorization', 'Bearer '.($this->deviceTokens[$reader->id] ??= MobileAccessToken::issue($reader)));
    }

    public function test_a_cycle_is_prepared_for_a_week_of_the_branch_and_its_reading_day_can_move_later_only(): void
    {
        $this->actingAs($this->branchAdmin)
            ->post(route('reading-cycles.store'), ['week_start' => '2026-10-16', 'reading_date' => '2026-10-21'])
            ->assertSessionHasErrors('reading_date');
        $this->actingAs($this->branchAdmin)
            ->post(route('reading-cycles.store'), ['week_start' => '2026-10-17', 'reading_date' => '2026-10-22'])
            ->assertSessionHasErrors('week_start');

        $this->actingAs($this->branchAdmin)
            ->post(route('reading-cycles.store'), ['week_start' => '2026-10-16', 'reading_date' => '2026-10-24'])
            ->assertSessionHasNoErrors();

        $cycle = ReadingCycle::query()->sole();
        $this->assertSame([$this->branch->id, '2026-10-22', '2026-10-24'], [$cycle->branch_id, $cycle->week_end->toDateString(), $cycle->reading_date->toDateString()]);

        $this->actingAs($this->branchAdmin)
            ->post(route('reading-cycles.store'), ['week_start' => '2026-10-16', 'reading_date' => '2026-10-22'])
            ->assertSessionHasErrors('week_start');
    }

    public function test_twenty_boxes_are_split_between_three_readers_and_paper_with_each_box_in_one_place(): void
    {
        $cycle = $this->cycle();
        $boxes = $this->boxes(20);
        [$ahmad, $mohammad, $sarah] = [$this->reader('أحمد'), $this->reader('محمد'), $this->reader('سارة')];

        $this->allocate($this->branchAdmin, $cycle, $boxes->slice(0, 5), 'mobile', $ahmad)->assertSessionHas('status', 'reading-boxes-allocated');
        $this->allocate($this->branchAdmin, $cycle, $boxes->slice(5, 5), 'mobile', $mohammad);
        $this->allocate($this->branchAdmin, $cycle, $boxes->slice(10, 5), 'mobile', $sarah);
        $this->allocate($this->branchAdmin, $cycle, $boxes->slice(15, 5), 'paper');

        $this->assertSame(20, ReadingAssignment::query()->count());
        $this->assertSame(5, ReadingAssignment::query()->where('user_id', $ahmad->id)->count());
        $this->assertSame(5, ReadingAssignment::query()->where('channel', ReadingAssignmentChannel::Paper)->count());

        $this->actingAs($this->branchAdmin)->get(route('reading-cycles.show', $cycle))
            ->assertInertia(fn ($page) => $page
                ->component('ReadingCycles/Show')
                ->where('boards', fn ($boards) => collect($boards)->countBy('column')->all() === ['reader:'.$ahmad->id => 5, 'reader:'.$mohammad->id => 5, 'reader:'.$sarah->id => 5, 'paper' => 5]));
    }

    public function test_many_boxes_dropped_on_one_reader_move_together(): void
    {
        $cycle = $this->cycle();
        $reader = $this->reader('أحمد');

        $this->allocate($this->branchAdmin, $cycle, $this->boxes(20), 'mobile', $reader)->assertSessionHasNoErrors();

        $this->assertSame(20, ReadingAssignment::query()->where('user_id', $reader->id)->where('status', ReadingAssignmentStatus::Draft)->count());
    }

    public function test_a_box_moved_by_someone_else_first_is_reported_as_a_conflict(): void
    {
        $cycle = $this->cycle();
        $box = $this->boxes(1)->first();
        $otherAdmin = User::factory()->branchAdmin()->create(['branch_id' => $this->branch->id]);
        $ahmad = $this->reader('أحمد');
        $sarah = $this->reader('سارة');

        // Both saw the box available (version 0); the first allocation wins.
        $this->allocate($this->branchAdmin, $cycle, [$box], 'mobile', $ahmad, ['versions' => [$box->id => 0]])->assertSessionHasNoErrors();
        $this->allocate($otherAdmin, $cycle, [$box], 'mobile', $sarah, ['versions' => [$box->id => 0]])
            ->assertSessionHasErrors('box_ids')
            ->assertSessionHas('inertia.flash_data.allocationResult', fn (array $result): bool => $result['moved'] === 0 && $result['conflicts'][0]['boxId'] === $box->id);

        $this->assertSame($ahmad->id, ReadingAssignment::query()->sole()->user_id);
    }

    public function test_one_reader_is_approved_while_another_stays_draft_then_all_are_approved(): void
    {
        $cycle = $this->cycle();
        $boxes = $this->boxes(4);
        [$ahmad, $mohammad] = [$this->reader('أحمد'), $this->reader('محمد')];
        $this->allocate($this->branchAdmin, $cycle, $boxes->slice(0, 2), 'mobile', $ahmad);
        $this->allocate($this->branchAdmin, $cycle, $boxes->slice(2, 1), 'mobile', $mohammad);
        $this->allocate($this->branchAdmin, $cycle, $boxes->slice(3, 1), 'paper');

        $this->actingAs($this->branchAdmin)->post(route('reading-cycles.assignments.approve', $cycle), ['user_id' => $ahmad->id])
            ->assertSessionHas('status', 'reading-assignments-approved');

        $this->assertSame(['approved', 'approved'], ReadingAssignment::query()->where('user_id', $ahmad->id)->pluck('status')->map->value->all());
        $this->assertSame('draft', ReadingAssignment::query()->where('user_id', $mohammad->id)->sole()->status->value);

        $this->actingAs($this->branchAdmin)->post(route('reading-cycles.assignments.approve', $cycle), []);

        $this->assertSame(0, ReadingAssignment::query()->where('status', ReadingAssignmentStatus::Draft)->count());
    }

    public function test_approving_takes_its_own_permission(): void
    {
        $cycle = $this->cycle();
        $manager = User::factory()->dataEntry()->withPermissions([PermissionKey::ManageReadingAssignments])->create(['branch_id' => $this->branch->id]);
        $this->allocate($manager, $cycle, $this->boxes(1), 'paper')->assertSessionHasNoErrors();

        $this->actingAs($manager)->post(route('reading-cycles.assignments.approve', $cycle), [])->assertForbidden();
        $this->actingAs(User::factory()->collector()->create(['branch_id' => $this->branch->id]))->get(route('reading-cycles.index'))->assertForbidden();
        $this->actingAs(User::factory()->branchAdmin()->create())->get(route('reading-cycles.show', $cycle))->assertForbidden();
    }

    public function test_last_weeks_allocation_is_copied_as_editable_drafts_without_readings(): void
    {
        $previous = $this->cycle(['week_start' => '2026-10-09', 'week_end' => '2026-10-15', 'reading_date' => '2026-10-15']);
        $boxes = $this->boxes(5);
        $ahmad = $this->reader('أحمد');
        $leaving = $this->reader('غادر');
        $this->allocate($this->branchAdmin, $previous, $boxes->slice(0, 3), 'mobile', $ahmad);
        $this->allocate($this->branchAdmin, $previous, $boxes->slice(3, 1), 'mobile', $leaving);
        $this->allocate($this->branchAdmin, $previous, $boxes->slice(4, 1), 'paper');
        $this->actingAs($this->branchAdmin)->post(route('reading-cycles.assignments.approve', $previous), []);
        $leaving->update(['is_active' => false]);
        $cycle = $this->cycle();

        $this->actingAs($this->branchAdmin)->post(route('reading-cycles.copy-previous', $cycle))
            ->assertSessionHas('inertia.flash_data.allocationResult', fn (array $result): bool => $result['moved'] === 4 && count($result['conflicts']) === 1);

        $copied = $cycle->assignments()->get();
        $this->assertSame(4, $copied->count());
        $this->assertTrue($copied->every(fn (ReadingAssignment $assignment) => $assignment->status === ReadingAssignmentStatus::Draft && $assignment->downloaded_at === null));

        $this->allocate($this->branchAdmin, $cycle, $boxes->slice(0, 3), 'mobile', $this->reader('سارة'))->assertSessionHasNoErrors();
    }

    public function test_tasks_downloaded_before_the_reading_day_can_be_viewed_but_not_read_until_that_day(): void
    {
        $cycle = $this->cycle();
        $box = $this->boxes(1)->first();
        $subscription = $box->subscriptions()->sole();
        $reader = $this->reader('أحمد');
        $this->allocate($this->branchAdmin, $cycle, [$box], 'mobile', $reader);

        // A draft is not downloaded.
        $this->mobile($reader)->getJson(route('mobile.reading-assignments.index'))->assertOk()->assertJsonCount(0, 'assignments');
        $this->actingAs($this->branchAdmin)->post(route('reading-cycles.assignments.approve', $cycle), []);

        $this->travelTo(Carbon::parse('2026-10-21 10:00', 'Asia/Gaza'));
        $download = $this->mobile($reader)->getJson(route('mobile.reading-assignments.index'))
            ->assertOk()
            ->assertJsonPath('assignments.0.cycle.reading_date', '2026-10-22')
            ->assertJsonPath('assignments.0.cycle.entry_open', false)
            ->assertJsonPath('assignments.0.subscriptions.0.previous_reading', 100)
            ->json('assignments.0');
        $this->assertTrue(ReadingAssignment::query()->sole()->isDownloaded());

        $reading = fn (string $capturedAt) => [
            'operation_id' => (string) Str::uuid(),
            'assignment_id' => $download['id'],
            'authorization' => $download['authorization'],
            'subscription_id' => $subscription->id,
            'current_reading' => '160.5',
            'captured_at' => Carbon::parse($capturedAt, 'Asia/Gaza')->toIso8601String(),
        ];

        $this->mobile($reader)->postJson(route('mobile.reading-assignments.sync'), ['readings' => [$reading('2026-10-21 10:05')]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'needs_review')
            ->assertJsonPath('results.0.issue', 'outside_reading_day');
        $this->assertSame(0, MeterReading::query()->count());

        $this->travelTo(Carbon::parse('2026-10-22 18:00', 'Asia/Gaza'));
        $thursday = $reading('2026-10-22 11:00');
        $this->mobile($reader)->postJson(route('mobile.reading-assignments.sync'), ['readings' => [$thursday]])
            ->assertJsonPath('results.0.status', 'accepted');
        // The same upload retried creates nothing more.
        $this->mobile($reader)->postJson(route('mobile.reading-assignments.sync'), ['readings' => [$thursday]])
            ->assertJsonPath('results.0.status', 'accepted');

        $meterReading = MeterReading::query()->sole();
        $this->mobile($this->reader('قارئ آخر'))->postJson(route('mobile.reading-assignments.sync'), ['readings' => [$thursday]])
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.issue', 'not_your_assignment')
            ->assertJsonPath('results.0.meter_reading_id', null);

        $this->assertSame(['2026-10-16', 160.5, 60.5, MeterReadingStatus::Pending, $reader->id], [$meterReading->week_start->toDateString(), $meterReading->current_reading, $meterReading->consumption, $meterReading->status, $meterReading->recorded_by]);
        $this->assertSame(2, ReadingSubmission::query()->count());
    }

    public function test_a_readings_clock_ahead_of_the_server_or_a_forged_authorisation_is_not_trusted(): void
    {
        ReadingEntrySetting::factory()->forBranch($this->branch)->create(['assignment_lead_days' => 2]);
        $cycle = $this->cycle();
        $box = $this->boxes(1)->first();
        $reader = $this->reader('أحمد');
        $this->allocate($this->branchAdmin, $cycle, [$box], 'mobile', $reader);
        $this->actingAs($this->branchAdmin)->post(route('reading-cycles.assignments.approve', $cycle), []);
        $download = $this->mobile($reader)->getJson(route('mobile.reading-assignments.index'))->json('assignments.0');

        // Tuesday on the server, Thursday on the phone.
        $this->mobile($reader)->postJson(route('mobile.reading-assignments.sync'), ['readings' => [[
            'operation_id' => (string) Str::uuid(), 'assignment_id' => $download['id'], 'authorization' => $download['authorization'],
            'subscription_id' => $box->subscriptions()->value('id'), 'current_reading' => '150', 'captured_at' => Carbon::parse('2026-10-22 11:00', 'Asia/Gaza')->toIso8601String(),
        ]]])->assertJsonPath('results.0.issue', 'untrusted_clock');

        $this->mobile($reader)->postJson(route('mobile.reading-assignments.sync'), ['readings' => [[
            'operation_id' => (string) Str::uuid(), 'assignment_id' => $download['id'], 'authorization' => $download['authorization'].'x',
            'subscription_id' => $box->subscriptions()->value('id'), 'current_reading' => '150', 'captured_at' => now()->toIso8601String(),
        ]]])->assertJsonPath('results.0.status', 'rejected')->assertJsonPath('results.0.issue', 'invalid_authorization');
    }

    public function test_reassigning_a_downloaded_box_needs_confirmation_and_the_old_readers_readings_are_held_for_review(): void
    {
        ReadingEntrySetting::factory()->forBranch($this->branch)->create(['assignment_lead_days' => 2]);
        $cycle = $this->cycle();
        $box = $this->boxes(1)->first();
        [$ahmad, $sarah] = [$this->reader('أحمد'), $this->reader('سارة')];
        $this->allocate($this->branchAdmin, $cycle, [$box], 'mobile', $ahmad);
        $this->actingAs($this->branchAdmin)->post(route('reading-cycles.assignments.approve', $cycle), []);
        $download = $this->mobile($ahmad)->getJson(route('mobile.reading-assignments.index'))->json('assignments.0');

        $this->allocate($this->branchAdmin, $cycle, [$box], 'mobile', $sarah)
            ->assertSessionHas('inertia.flash_data.allocationResult', fn (array $result): bool => $result['moved'] === 0);
        $this->allocate($this->branchAdmin, $cycle, [$box], 'mobile', $sarah, ['confirm_downloaded' => true])->assertSessionHasNoErrors();

        $assignment = ReadingAssignment::query()->sole();
        $this->assertSame([$sarah->id, 2, ReadingAssignmentStatus::Draft], [$assignment->user_id, $assignment->version, $assignment->status]);
        $this->assertSame('reassigned', $assignment->events()->latest('id')->value('action'));

        $this->travelTo(Carbon::parse('2026-10-22 12:00', 'Asia/Gaza'));
        $this->mobile($ahmad)->postJson(route('mobile.reading-assignments.sync'), ['readings' => [[
            'operation_id' => (string) Str::uuid(), 'assignment_id' => $download['id'], 'authorization' => $download['authorization'],
            'subscription_id' => $box->subscriptions()->value('id'), 'current_reading' => '150', 'captured_at' => now()->subHour()->toIso8601String(),
        ]]])->assertJsonPath('results.0.status', 'needs_review')->assertJsonPath('results.0.issue', 'assignment_changed');

        $held = ReadingSubmission::query()->sole();
        $this->assertSame(150.0, $held->current_reading);
        $this->assertSame(0, MeterReading::query()->count());

        // A supervisor saves it.
        $this->actingAs($this->branchAdmin)->post(route('reading-submissions.review', $held), ['decision' => 'accept', 'note' => 'قرأها أحمد قبل النقل'])
            ->assertSessionHas('status', 'reading-submission-accepted');
        $this->assertSame(ReadingSubmissionStatus::Accepted, $held->fresh()->status);
        $this->assertSame(150.0, MeterReading::query()->sole()->current_reading);
    }

    public function test_even_the_super_admin_cannot_accept_a_held_mobile_reading_after_its_week_is_locked(): void
    {
        $cycle = $this->cycle();
        $box = $this->boxes(1)->first();
        $reader = $this->reader('أحمد');
        $this->allocate($this->branchAdmin, $cycle, [$box], 'mobile', $reader);
        $this->actingAs($this->branchAdmin)->post(route('reading-cycles.assignments.approve', $cycle), []);
        $this->travelTo(Carbon::parse('2026-10-22 10:00', 'Asia/Gaza'));
        $download = $this->mobile($reader)->getJson(route('mobile.reading-assignments.index'))->json('assignments.0');
        $this->travelTo(Carbon::parse('2026-10-29 10:00', 'Asia/Gaza'));
        $this->mobile($reader)->postJson(route('mobile.reading-assignments.sync'), ['readings' => [[
            'operation_id' => (string) Str::uuid(), 'assignment_id' => $download['id'], 'authorization' => $download['authorization'],
            'subscription_id' => $box->subscriptions()->value('id'), 'current_reading' => 150,
            'captured_at' => Carbon::parse('2026-10-22 11:00', 'Asia/Gaza')->toIso8601String(),
        ]]])->assertJsonPath('results.0.status', 'needs_review');
        $held = ReadingSubmission::query()->sole();

        $this->actingAs(User::factory()->superAdmin()->create())->post(route('reading-submissions.review', $held), ['decision' => 'accept'])
            ->assertSessionHasErrors(['decision' => ReadingSubmission::ISSUES['week_locked']]);

        $this->assertSame(ReadingSubmissionStatus::NeedsReview, $held->fresh()->status);
        $this->assertDatabaseCount('meter_readings', 0);
        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_postponing_a_mobile_cycle_does_not_reopen_an_older_week_for_automatic_sync(): void
    {
        $cycle = $this->cycle(['reading_date' => '2026-10-29']);
        $box = $this->boxes(1)->first();
        $reader = $this->reader('أحمد');
        $this->allocate($this->branchAdmin, $cycle, [$box], 'mobile', $reader);
        $this->actingAs($this->branchAdmin)->post(route('reading-cycles.assignments.approve', $cycle), []);
        $this->travelTo(Carbon::parse('2026-10-29 10:00', 'Asia/Gaza'));
        $download = $this->mobile($reader)->getJson(route('mobile.reading-assignments.index'))->json('assignments.0');

        $this->mobile($reader)->postJson(route('mobile.reading-assignments.sync'), ['readings' => [[
            'operation_id' => (string) Str::uuid(), 'assignment_id' => $download['id'], 'authorization' => $download['authorization'],
            'subscription_id' => $box->subscriptions()->value('id'), 'current_reading' => 150,
            'captured_at' => now()->subHour()->toIso8601String(),
        ]]])->assertJsonPath('results.0.status', 'needs_review')->assertJsonPath('results.0.issue', 'week_locked');

        $this->assertDatabaseCount('meter_readings', 0);
    }

    public function test_a_second_final_reading_for_the_same_week_is_held_not_saved(): void
    {
        $cycle = $this->cycle(['reading_date' => '2026-10-20']);
        $box = $this->boxes(1)->first();
        $subscription = $box->subscriptions()->sole();
        $reader = $this->reader('أحمد');
        $this->allocate($this->branchAdmin, $cycle, [$box], 'mobile', $reader);
        $this->actingAs($this->branchAdmin)->post(route('reading-cycles.assignments.approve', $cycle), []);
        $download = $this->mobile($reader)->getJson(route('mobile.reading-assignments.index'))->json('assignments.0');
        $this->assertTrue($download['cycle']['entry_open']);
        MeterReading::recordFor($subscription->load(['tariff', 'circuitBreaker', 'standingDiscount']), Carbon::parse('2026-10-16'), 140, $this->branchAdmin, null);

        $this->mobile($reader)->postJson(route('mobile.reading-assignments.sync'), ['readings' => [[
            'operation_id' => (string) Str::uuid(), 'assignment_id' => $download['id'], 'authorization' => $download['authorization'],
            'subscription_id' => $subscription->id, 'current_reading' => '150', 'captured_at' => now()->toIso8601String(),
        ]]])->assertJsonPath('results.0.issue', 'already_read');

        $this->assertSame(1, MeterReading::query()->count());
    }

    public function test_printing_a_paper_sheet_twice_counts_the_reprint_without_allocating_again(): void
    {
        $cycle = $this->cycle();
        $boxes = $this->boxes(3);
        Subscription::query()->whereIn('meter_box_id', $boxes->pluck('id'))->update(['subscription_phone' => '0599000001']);
        $this->allocate($this->branchAdmin, $cycle, $boxes, 'paper');

        $this->actingAs($this->branchAdmin)->post(route('reading-cycles.paper-sheets.store', $cycle), ['box_ids' => $boxes->pluck('id')->all()])
            ->assertSessionHasErrors('box_ids');
        $this->actingAs($this->branchAdmin)->post(route('reading-cycles.assignments.approve', $cycle), ['paper' => true]);
        $created = $this->actingAs($this->branchAdmin)->post(route('reading-cycles.paper-sheets.store', $cycle), ['box_ids' => $boxes->pluck('id')->all()]);

        $sheet = PaperSheet::query()->sole();
        $created->assertRedirect(route('paper-sheets.show', [$sheet, 'print' => 1]));
        $this->actingAs($this->branchAdmin)->get(route('paper-sheets.show', $sheet))
            ->assertInertia(fn ($page) => $page->component('ReadingCycles/PaperSheet')->has('boxes', 3)->where('boxes.0.subscriptions.0.previousReading', 100)->where('boxes.0.subscriptions.0.phone', '0599000001'));
        $this->actingAs($this->branchAdmin)->postJson(route('paper-sheets.printed', $sheet))->assertOk()->assertJsonPath('printed', true);
        $this->actingAs($this->branchAdmin)->post(route('paper-sheets.printed', $sheet));

        $this->assertSame(2, $sheet->fresh()->print_count);
        $this->assertSame(3, ReadingAssignment::query()->count());
        $this->assertSame(3, $sheet->assignments()->count());
    }

    public function test_a_board_without_active_subscribers_cannot_be_allocated(): void
    {
        $cycle = $this->cycle();
        $empty = MeterBox::factory()->create(['branch_id' => $this->branch->id]);
        $disconnectedOnly = MeterBox::factory()->create(['branch_id' => $this->branch->id]);
        Subscription::factory()->create(['branch_id' => $this->branch->id, 'meter_box_id' => $disconnectedOnly->id, 'status' => SubscriptionStatus::Disconnected]);

        $this->allocate($this->branchAdmin, $cycle, [$empty, $disconnectedOnly], 'paper')->assertSessionHasErrors('box_ids');

        $this->assertSame(0, ReadingAssignment::query()->count());
    }
}
