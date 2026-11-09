<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Closing;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BillingPeriods;
use App\Support\BranchClosingWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A branch working by billing periods keeps its collectors' boxes open as
 * long as its period is open, however many weeks pass; they close only
 * when the manager closes the period by hand (or the branch confirms a box
 * closed, as before). A branch without periods keeps its weekly boxes.
 */
class BoxesFollowBillingPeriodsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $manager;

    private User $collector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-11 10:00', config('app.business_timezone')));
        $this->branch = Branch::factory()->create();
        $this->manager = User::factory()->branchAdmin()->for($this->branch)->create();
        $this->collector = User::factory()->collector()->for($this->branch)->create();
    }

    private function openPeriod(string $start, string $end): void
    {
        app(BillingPeriods::class)->forget();
        app(BillingPeriods::class)->create($this->branch, CarbonImmutable::parse($start), CarbonImmutable::parse($end), $this->manager);
    }

    private function pay(string $amount = '100'): SubscriptionTransaction
    {
        app(BillingPeriods::class)->forget();

        return SubscriptionTransaction::recordPayment(Subscription::factory()->for($this->branch)->create(), $this->collector, ['amount' => $amount, 'currency' => 'ILS', 'payment_method' => 'cash']);
    }

    private function process(): void
    {
        app(BillingPeriods::class)->forget();
        $this->artisan('closings:process')->assertSuccessful();
    }

    public function test_the_boxes_stay_open_for_weeks_while_the_billing_period_is_open(): void
    {
        $this->openPeriod('2026-10-11', '2026-10-17');
        $this->pay();
        $box = CashBox::query()->where('user_id', $this->collector->id)->sole();

        // Three weeks on, past the period's last day and every weekly boundary.
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00', config('app.business_timezone')));
        $this->process();
        $this->pay('40');

        $this->assertSame('open', $box->fresh()->status);
        $this->assertSame(1, CashBox::query()->where('user_id', $this->collector->id)->count());
        $this->assertSame(2, $box->payments()->count());
        $this->assertSame(1, Closing::query()->where('branch_id', $this->branch->id)->whereNotNull('workflow_settings')->count());
    }

    public function test_closing_the_billing_period_by_hand_closes_its_boxes_and_starts_new_ones(): void
    {
        $this->openPeriod('2026-10-11', '2026-10-17');
        $this->pay();
        $box = CashBox::query()->where('user_id', $this->collector->id)->sole();
        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00', config('app.business_timezone')));
        $this->process();
        $this->assertSame('open', $box->fresh()->status);

        $this->actingAs($this->manager)->post(route('billing-periods.close', app(BillingPeriods::class)->open($this->branch->id)))->assertSessionHasNoErrors();

        $closed = $box->fresh();
        $this->assertSame(['received', CashBox::CLOSE_REASON_PERIOD_END], [$closed->status, $closed->close_reason]);
        $this->assertStringContainsString('أُغلق مع إغلاق فترة الفوترة.', $closed->reason);
        $this->assertSame('100.00', $closed->expected_counts['ILS']);
        $this->assertNull($closed->received_by);

        // The next period's payments go into new boxes, of a new box period begun when the last ended.
        $this->openPeriod('2026-10-25', '2026-10-31');
        $this->travel(5)->minutes();
        $this->pay('30');
        $new = CashBox::query()->where('user_id', $this->collector->id)->where('status', 'open')->sole();
        $this->assertNotSame($box->id, $new->id);
        $this->assertTrue($new->closing->coverage_from->equalTo($closed->closing->coverage_until));
    }

    public function test_a_branch_without_billing_periods_keeps_closing_its_boxes_weekly(): void
    {
        app(BranchClosingWorkflow::class)->start($this->branch->id, $this->manager);
        $this->pay();
        $box = CashBox::query()->where('user_id', $this->collector->id)->sole();

        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00', config('app.business_timezone')));
        $this->process();

        $this->assertSame(['received', CashBox::CLOSE_REASON_PERIOD_END], [$box->fresh()->status, $box->fresh()->close_reason]);
        $this->assertStringContainsString('أُغلق تلقائيًا لانتهاء الفترة الأسبوعية.', $box->fresh()->reason);
    }

    public function test_the_branch_still_confirms_a_box_closed_by_hand_while_the_period_is_open(): void
    {
        $this->openPeriod('2026-10-11', '2026-10-17');
        $this->pay();
        $box = CashBox::query()->where('user_id', $this->collector->id)->sole();

        app(BranchClosingWorkflow::class)->receive($box, $this->manager);

        $this->assertSame(['received', 'manual', $this->manager->id], [$box->fresh()->status, $box->fresh()->close_reason, $box->fresh()->received_by]);
    }
}
