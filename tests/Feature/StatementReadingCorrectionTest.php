<?php

namespace Tests\Feature;

use App\Enums\MeterReadingStatus;
use App\Models\Branch;
use App\Models\MeterReading;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Notifications\ReadingNeedsReapproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StatementReadingCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private User $branchAdmin;

    private Subscription $subscription;

    private MeterReading $reading;

    protected function setUp(): void
    {
        parent::setUp();

        // The week of 21–27 August has just ended, so its readings may still be corrected.
        $this->travelTo('2026-08-28 10:00:00');
        $branch = Branch::factory()->create();
        $this->branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);
        $this->subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $this->reading = $this->approvedReading('2026-08-21', '2026-08-27', 1000, 1179, '53.70');
    }

    public function test_a_weekly_reading_line_offers_its_reading_to_correct_instead_of_an_amount_edit(): void
    {
        $line = $this->readingLine();

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.0.id', $line->id)
                ->where('entries.0.available_actions', [])
                ->where('entries.0.meterReadingId', $this->reading->id)
                ->where('entries.0.reading.id', $this->reading->id)
                ->where('entries.0.reading.weekStart', '2026-08-21')
                ->where('entries.0.reading.previous_reading', 1000)
                ->where('entries.0.reading.current_reading', 1179)
                ->where('entries.0.reading.status', 'approved')
                ->where('entries.0.reading.canCorrect', true)
                ->where('entries.0.reading.correctUnavailableReason', null));
    }

    public function test_the_amount_of_a_weekly_reading_line_cannot_be_edited_in_place(): void
    {
        $line = $this->readingLine();

        $this->actingAs($this->branchAdmin)
            ->post(route('subscriptions.transactions.actions.store', [$this->subscription, $line]), ['action' => 'edit', 'amount' => '10', 'amendment_reason' => 'خطأ'])
            ->assertSessionHasErrors(['action' => 'هذا الإجراء غير مسموح لهذه الحركة.']);

        $this->assertSame('53.70', $line->refresh()->amount);
    }

    public function test_correcting_the_reading_cancels_its_line_and_bills_the_corrected_amount_once_approved_again(): void
    {
        $line = $this->readingLine();

        $this->actingAs($this->branchAdmin)
            ->from(route('subscriptions.statement', $this->subscription))
            ->put(route('meter-readings.update', $this->reading), ['current_reading' => '1100'])
            ->assertRedirect(route('subscriptions.statement', $this->subscription))
            ->assertSessionHas('status', 'meter-reading-reopened');

        $this->assertNotNull($line->refresh()->cancelled_at);
        $this->assertSame(0.0, $this->subscription->balance());
        $this->assertSame(MeterReadingStatus::Pending, $this->reading->refresh()->status);

        $this->reading->approve($this->branchAdmin);

        $replacement = SubscriptionTransaction::query()->where('type', SubscriptionTransaction::TYPE_METER_READING)->whereNull('cancelled_at')->sole();
        $this->assertSame($line->id, $replacement->corrects_id);
        $this->assertSame((float) $this->reading->refresh()->amountBeforeDiscount(), (float) $replacement->amount);
        $this->assertSame(round((float) $replacement->amount, 2), $this->subscription->balance());
    }

    public function test_a_reading_followed_by_a_later_week_cannot_be_corrected(): void
    {
        $this->travelTo('2026-09-04 10:00:00');
        $this->approvedReading('2026-08-28', '2026-09-03', 1179, 1250, '21.30');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.0.reading.canCorrect', false)
                ->where('entries.0.reading.correctUnavailableReason', 'توجد قراءة لأسبوع لاحق')
                ->where('entries.1.reading.canCorrect', true));
    }

    public function test_users_who_do_not_record_readings_are_not_offered_the_correction(): void
    {
        $accountant = User::factory()->accountant()->create(['branch_id' => $this->subscription->branch_id]);

        $this->assertFalse($accountant->can('create', MeterReading::class));
        $this->actingAs($accountant)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page->where('entries.0.reading', null));
    }

    public function test_someone_who_may_approve_readings_can_correct_and_approve_in_one_step(): void
    {
        Notification::fake();
        $line = $this->readingLine();

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page->where('entries.0.reading.canApprove', true));

        $this->actingAs($this->branchAdmin)
            ->from(route('subscriptions.statement', $this->subscription))
            ->put(route('meter-readings.update', $this->reading), ['current_reading' => '1100', 'approve' => true])
            ->assertRedirect(route('subscriptions.statement', $this->subscription))
            ->assertSessionHas('status', 'meter-reading-corrected-approved');

        $this->assertNotNull($line->refresh()->cancelled_at);
        $this->assertSame(MeterReadingStatus::Approved, $this->reading->refresh()->status);
        $replacement = SubscriptionTransaction::query()->where('type', SubscriptionTransaction::TYPE_METER_READING)->whereNull('cancelled_at')->sole();
        $this->assertSame($line->id, $replacement->corrects_id);
        $this->assertSame((float) $this->reading->amountBeforeDiscount(), (float) $replacement->amount);
        $this->assertSame(round((float) $replacement->amount, 2), $this->subscription->balance());
        Notification::assertNotSentTo($this->reading->approvers(), ReadingNeedsReapproval::class);
    }

    public function test_asking_to_approve_without_the_permission_still_sends_the_reading_back_for_approval(): void
    {
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $this->subscription->branch_id]);
        $line = $this->readingLine();

        $this->actingAs($dataEntry)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page->where('entries.0.reading.canCorrect', true)->where('entries.0.reading.canApprove', false));

        $this->actingAs($dataEntry)
            ->put(route('meter-readings.update', $this->reading), ['current_reading' => '1100', 'approve' => true])
            ->assertSessionHas('status', 'meter-reading-reopened');

        $this->assertNotNull($line->refresh()->cancelled_at);
        $this->assertSame(MeterReadingStatus::Pending, $this->reading->refresh()->status);
        $this->assertSame(0, SubscriptionTransaction::query()->where('type', SubscriptionTransaction::TYPE_METER_READING)->whereNull('cancelled_at')->count());
    }

    private function approvedReading(string $weekStart, string $weekEnd, int $previous, int $current, string $amountDue): MeterReading
    {
        $reading = MeterReading::factory()->create([
            'subscription_id' => $this->subscription->id,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'previous_reading' => $previous,
            'current_reading' => $current,
            'consumption' => $current - $previous,
            'amount_due' => $amountDue,
        ]);
        $reading->approve($this->branchAdmin);

        return $reading;
    }

    private function readingLine(): SubscriptionTransaction
    {
        return SubscriptionTransaction::query()->where('meter_reading_id', $this->reading->id)->where('type', SubscriptionTransaction::TYPE_METER_READING)->sole();
    }
}
