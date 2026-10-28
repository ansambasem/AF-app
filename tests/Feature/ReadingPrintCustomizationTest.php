<?php

namespace Tests\Feature;

use App\Enums\MeterReadingStatus;
use App\Enums\PermissionKey;
use App\Enums\ReadingAssignmentChannel;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\MeterReading;
use App\Models\Permission;
use App\Models\PrintTemplate;
use App\Models\ReadingAssignment;
use App\Models\ReadingCycle;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadingPrintCustomizationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{branch: Branch, manager: User, reader: User, box: MeterBox, subscription: Subscription, cycle: ReadingCycle, assignment: ReadingAssignment} */
    private function work(): array
    {
        $this->travelTo('2026-10-22 10:00:00');
        $branch = Branch::factory()->create();
        $manager = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);
        $reader = User::factory()->dataEntry()->create(['branch_id' => $branch->id]);
        $box = MeterBox::factory()->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id, 'meter_box_id' => $box->id, 'phone' => '0599000111']);
        $cycle = ReadingCycle::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $assignment = ReadingAssignment::factory()->mobile($reader)->approved()->create(['branch_id' => $branch->id, 'reading_cycle_id' => $cycle->id, 'meter_box_id' => $box->id, 'assigned_by' => $manager->id, 'approved_by' => $manager->id]);

        return compact('branch', 'manager', 'reader', 'box', 'subscription', 'cycle', 'assignment');
    }

    private function reading(Subscription $subscription, User $reader): MeterReading
    {
        return MeterReading::factory()->for($subscription)->create([
            'week_start' => MeterReading::latestEndedWeekStart($subscription->branch_id),
            'previous_reading' => 100, 'current_reading' => 105, 'consumption' => 5,
            'unit_price' => 3, 'reading_fee' => 15, 'minimum_payment' => 10, 'amount_due' => 15,
            'recorded_by' => $reader->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function printData(string $url): array
    {
        return $this->get($url, $this->inertiaHeaders())->assertOk()->assertJsonPath('component', 'Print/ReadingReport')->json('props.printData');
    }

    /** @return array<string, string> */
    private function inertiaHeaders(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
    }

    public function test_weekly_print_fields_do_not_expose_audit_fields_to_a_reader(): void
    {
        $work = $this->work();
        $this->reading($work['subscription'], $work['reader']);
        $this->actingAs($work['reader']);
        $data = $this->printData(route('meter-readings.index', ['print' => 1, 'print_all' => 1]));
        $this->assertSame('/meter-readings', $data['context']['templatePage']);
        $this->assertSame('100', $data['rows'][0]['cells']['القراءة السابقة']);
        $this->assertSame('105', $data['rows'][0]['cells']['القراءة الحالية']);
        $this->assertSame('0599000111', $data['rows'][0]['cells']['field:phone']);
        $this->assertArrayHasKey('field:tariffName', $data['rows'][0]['cells']);
        $this->assertArrayNotHasKey('field:recordedBy', $data['rows'][0]['cells']);
        $this->assertNotContains('field:recordedBy', array_column($data['columns'], 'key'));
        $this->get(route('meter-readings.index'), $this->inertiaHeaders())->assertOk()->assertJsonMissingPath('props.printData')->assertJsonMissingPath('props.rows.data.0.printDetails');
    }

    public function test_distribution_printing_respects_area_reader_filters_and_branch_scope(): void
    {
        $work = $this->work();
        $this->actingAs($work['manager']);
        $data = $this->printData(route('reading-cycles.print', ['print' => 1, 'print_all' => 1, 'cycle' => $work['cycle']->id, 'assignment' => 'reader:'.$work['reader']->id]));
        $this->assertSame(1, $data['total']);
        $this->assertSame($work['reader']->name, $data['rows'][0]['cells']['القارئ']);
        $this->assertSame($work['box']->box_number, $data['rows'][0]['cells']['field:boxNumber']);
        $none = $this->printData(route('reading-cycles.print', ['print' => 1, 'cycle' => $work['cycle']->id, 'assignment' => 'available']));
        $this->assertSame([], $none['rows']);
        $wrongArea = $this->printData(route('reading-cycles.print', ['print' => 1, 'cycle' => $work['cycle']->id, 'area_ids' => ['999999']]));
        $this->assertSame([], $wrongArea['rows']);
        $otherCycle = ReadingCycle::factory()->create();
        $this->get(route('reading-cycles.print', ['print' => 1, 'cycle' => $otherCycle->id]))->assertNotFound();
    }

    public function test_excel_distribution_pages_advance_without_losing_the_cycle_or_filters(): void
    {
        $work = $this->work();
        MeterBox::factory()->count(15)->has(Subscription::factory()->state(['branch_id' => $work['branch']->id, 'circuit_breaker_id' => $work['subscription']->circuit_breaker_id]), 'subscriptions')->create(['branch_id' => $work['branch']->id]);
        $this->actingAs($work['manager']);
        $url = route('reading-cycles.print', ['print' => 1, 'excel' => 1, 'cycle' => $work['cycle']->id, 'per_page' => 15]);

        $first = $this->printData($url);
        $second = $this->printData($first['pagination']['next_page_url']);

        $this->assertCount(15, $first['rows']);
        $this->assertCount(1, $second['rows']);
        $this->assertSame(2, $second['pagination']['current_page']);
        $this->assertSame(16, $second['pagination']['total']);
        $this->assertNull($second['pagination']['next_page_url']);
        $this->assertNotContains($second['rows'][0]['cells']['field:boxNumber'], array_column(array_column($first['rows'], 'cells'), 'field:boxNumber'));
        $foreign = ReadingCycle::factory()->create();
        $this->get(route('reading-cycles.print', ['print' => 1, 'excel' => 1, 'cycle' => $foreign->id, 'page' => 2]))->assertNotFound();
    }

    public function test_printing_marks_cancelled_bills_and_does_not_print_stale_reentry_charges(): void
    {
        $work = $this->work();
        $reading = $this->reading($work['subscription'], $work['reader']);
        $reading->status = MeterReadingStatus::Approved;
        $reading->save();
        $this->actingAs($work['manager']);
        $data = $this->printData(route('meter-readings.index', ['print' => 1, 'print_all' => 1]));
        $cells = $data['rows'][0]['cells'];
        $this->assertSame('0', $cells['المطلوب دفعه']);
        $this->assertStringContainsString('فاتورة ملغاة', $cells['الحالة']);
        $this->assertSame('15.00', $cells['field:originalAmountDue']);
        $reading->requires_reentry = true;
        $reading->status = MeterReadingStatus::Pending;
        $reading->save();
        $data = $this->printData(route('meter-readings.index', ['print' => 1, 'print_all' => 1]));
        $cells = $data['rows'][0]['cells'];
        $this->assertSame('', $cells['القراءة الحالية']);
        $this->assertSame('', $cells['الفرق (كيلو)']);
        $this->assertSame('', $cells['المطلوب دفعه']);
        $this->assertSame('بانتظار إدخال الحالية', $cells['الحالة']);
    }

    public function test_selected_readings_are_bound_to_the_user_session_type_and_expiry(): void
    {
        $work = $this->work();
        $reading = $this->reading($work['subscription'], $work['reader']);
        $other = Subscription::factory()->create(['branch_id' => $work['branch']->id]);
        $this->reading($other, $work['reader']);
        $this->actingAs($work['reader']);
        $token = $this->postJson(route('print-selections.store'), ['page' => '/meter-readings', 'ids' => [$reading->id]])->assertOk()->json('token');
        $this->withCookie(config('session.cookie'), session()->getId());
        $url = route('meter-readings.index', ['print' => 1, 'print_all' => 1, 'print_scope' => 'selected', 'print_selection' => $token]);
        $data = $this->printData($url);
        $this->assertSame(1, $data['total']);
        $this->actingAs($work['manager'])->get($url)->assertStatus(419);
        $this->actingAs($work['reader']);
        $this->get(route('reading-cycles.print', ['print' => 1, 'cycle' => $work['cycle']->id, 'print_scope' => 'selected', 'print_selection' => $token]))->assertStatus(419);
        $sessionId = session()->getId();
        $this->withCookie(config('session.cookie'), str_repeat('a', 40))->get($url)->assertStatus(419);
        $this->withCookie(config('session.cookie'), $sessionId);
        $this->travel(61)->minutes();
        $this->get($url)->assertStatus(419);
    }

    public function test_template_only_reloads_do_not_query_the_reports_rows(): void
    {
        $work = $this->work();
        $sheet = $work['cycle']->paperSheets()->create(['sheet_number' => 'LAZY', 'created_by' => $work['manager']->id]);
        $sheet->assignments()->attach($work['assignment']);
        $this->actingAs($work['manager']);
        $urls = [
            route('meter-readings.index', ['print' => 1, 'print_all' => 1]),
            route('reading-cycles.print', ['print' => 1, 'cycle' => $work['cycle']->id]),
            route('paper-sheets.show', [$sheet, 'print' => 1]),
        ];
        foreach ($urls as $url) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->get($url, [
                ...$this->inertiaHeaders(),
                'X-Inertia-Partial-Component' => 'Print/ReadingReport',
                'X-Inertia-Partial-Data' => 'printTemplates',
            ]);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $response->assertOk()->assertJsonMissingPath('props.printData');
            $rowQueries = array_filter($queries, fn (array $query): bool => preg_match('/from "(?:subscriptions|meter_readings|reading_assignments)"/', $query['query']) === 1);
            $this->assertCount(0, $rowQueries, $url);
        }
    }

    public function test_printing_selection_does_not_bypass_branch_or_assignment_filters(): void
    {
        $work = $this->work();
        $foreignBox = MeterBox::factory()->create();
        $this->actingAs($work['manager']);
        $token = $this->postJson(route('print-selections.store'), ['page' => '/reading-cycles/print', 'ids' => [$work['box']->id, $foreignBox->id]])->assertOk()->json('token');
        $this->withCookie(config('session.cookie'), session()->getId());
        $params = ['cycle' => $work['cycle']->id, 'print' => 1, 'print_all' => 1, 'print_scope' => 'selected', 'print_selection' => $token];
        $data = $this->printData(route('reading-cycles.print', $params));
        $this->assertCount(1, $data['rows']);
        $this->assertSame($work['box']->box_number, $data['rows'][0]['cells']['field:boxNumber']);
        $filtered = $this->printData(route('reading-cycles.print', [...$params, 'assignment' => 'available']));
        $this->assertSame([], $filtered['rows']);
    }

    public function test_reading_print_routes_keep_the_additional_auditor_print_restriction(): void
    {
        $work = $this->work();
        $actor = $work['manager'];
        $actor->permissions()->syncWithoutDetaching(Permission::idsFor([PermissionKey::AuditClosings]));
        $actor->audit_settings = ['scope' => 'all', 'branch_ids' => [], 'actions' => ['view_accounts']];
        $actor->save();
        $sheet = $work['cycle']->paperSheets()->create(['sheet_number' => 'AUDIT', 'created_by' => $actor->id]);
        $this->actingAs($actor->fresh());
        $this->get(route('meter-readings.index'))->assertOk();
        foreach ([
            route('meter-readings.index', ['print' => 1]),
            route('reading-cycles.print', ['print' => 1, 'cycle' => $work['cycle']->id]),
            route('paper-sheets.index', ['print' => 1]),
            route('paper-sheets.show', [$sheet, 'print' => 1]),
        ] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->postJson(route('print-selections.store'), ['page' => '/meter-readings', 'ids' => [1]])->assertForbidden();
        $actor->audit_settings = ['scope' => 'all', 'branch_ids' => [], 'actions' => ['view_accounts', 'print_reports']];
        $actor->save();
        $this->actingAs($actor->fresh())->get(route('reading-cycles.print', ['print' => 1, 'cycle' => $work['cycle']->id]))->assertOk();
    }

    public function test_paper_templates_are_shared_by_sheet_type_and_current_reading_stays_blank(): void
    {
        $work = $this->work();
        $work['assignment']->update(['channel' => ReadingAssignmentChannel::Paper, 'user_id' => null]);
        $sheet = $work['cycle']->paperSheets()->create(['sheet_number' => 'TEST-1', 'created_by' => $work['manager']->id]);
        $sheet->assignments()->attach($work['assignment']);
        $template = PrintTemplate::factory()->create(['page' => '/paper-sheets', 'is_default' => true]);
        $this->actingAs($work['manager']);
        $response = $this->get(route('paper-sheets.show', [$sheet, 'print' => 1]), $this->inertiaHeaders())->assertOk();
        $response->assertJsonPath('component', 'Print/ReadingReport')->assertJsonPath('props.printTemplates.0.id', $template->id)->assertJsonPath('props.printData.context.templatePage', '/paper-sheets')->assertJsonPath('props.printData.rows.0.cells.القراءة الحالية', '');
        $this->assertSame(0, $sheet->fresh()->print_count);
        $second = $work['cycle']->paperSheets()->create(['sheet_number' => 'TEST-2', 'created_by' => $work['manager']->id]);
        $second->assignments()->attach($work['assignment']);
        $this->get(route('paper-sheets.show', [$second, 'print' => 1]), $this->inertiaHeaders())->assertJsonPath('props.printTemplates.0.id', $template->id);
        $work['assignment']->update(['channel' => ReadingAssignmentChannel::Mobile, 'user_id' => $work['reader']->id]);
        $this->get(route('paper-sheets.show', [$second, 'print' => 1]), $this->inertiaHeaders())
            ->assertJsonCount(1, 'props.printData.warnings')
            ->assertJsonPath('props.printData.defaults.density', 'relaxed');
        $withoutPaper = User::factory()->dataEntry()->create(['branch_id' => $work['branch']->id]);
        $withoutPaper->permissions()->sync(Permission::idsFor([PermissionKey::RecordMeterReadings]));
        $this->actingAs($withoutPaper)->get(route('paper-sheets.show', [$sheet, 'print' => 1]))->assertForbidden();
    }

    public function test_print_routes_require_their_original_permissions(): void
    {
        $work = $this->work();
        $this->get(route('reading-cycles.print'))->assertRedirect(route('login'));
        $unprivileged = User::factory()->collector()->withPermissions([])->create(['branch_id' => $work['branch']->id]);
        $this->actingAs($unprivileged)->get(route('reading-cycles.print', ['print' => 1]))->assertForbidden();
        $this->postJson(route('print-selections.store'), ['page' => '/meter-readings', 'ids' => [1]])->assertForbidden();
        $this->actingAs($work['reader'])->postJson(route('print-selections.store'), ['page' => '/meter-readings', 'ids' => [1, 1]])->assertUnprocessable();
    }
}
