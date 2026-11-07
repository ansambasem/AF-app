<?php

namespace Tests\Feature\Lifecycle;

use App\Enums\MeterReadingStatus;
use App\Models\Branch;
use App\Models\MeterReading;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\Tariff;
use App\Models\User;
use App\Notifications\ReadingNeedsReapproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Four weeks of one subscriber's readings, each week on its Thursday, with
 * what the subscriber is billed after every step: a reading entered,
 * corrected while pending, approved, corrected after approval (back to
 * review, and approved again at once), billed with a standing discount, flagged as
 * unusual and approved only once confirmed, its bill cancelled to send it
 * back for review and billed afresh, and its bill waived.
 */
class WeeklyReadingLifecycleJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_four_weeks_of_readings_bill_the_subscriber_exactly_once_for_what_was_used(): void
    {
        $this->travelTo('2026-10-01 08:00:00');
        $branch = Branch::factory()->create();
        $head = User::factory()->branchAdmin()->for($branch)->create();
        $dataEntry = User::factory()->dataEntry()->for($branch)->create();
        $collector = User::factory()->collector()->for($branch)->create();
        $subscription = Subscription::factory()->for($branch)->create([
            'tariff_id' => Tariff::factory()->residential()->create(['rate' => '0.50'])->id, 'initial_reading' => 1000, 'minimum_charge' => '10.00',
        ]);
        Notification::fake();

        $this->record($dataEntry, $subscription, '2026-09-25', 1100)->assertSessionHas('status', 'meter-reading-created');
        $first = MeterReading::sole();
        $this->correct($dataEntry, $first, 1120)->assertSessionHas('status', 'meter-reading-updated');
        $this->assertSame('60.00', $first->fresh()->amount_due);
        $this->assertSame(0.0, $subscription->balance());
        $this->approve($head, $first)->assertSessionHasNoErrors();
        $this->assertSame(60.0, $subscription->balance());

        $this->correct($dataEntry, $first, 1110)->assertSessionHas('status', 'meter-reading-reopened');
        $this->assertSame(MeterReadingStatus::Pending, $first->fresh()->status);
        $this->assertSame(0.0, $subscription->balance());
        Notification::assertSentTo($head, ReadingNeedsReapproval::class);
        $this->approve($head, $first)->assertSessionHasNoErrors();
        $this->assertSame(55.0, $subscription->balance());
        $this->correct($head, $first, 1130, ['approve' => true])->assertSessionHas('status', 'meter-reading-corrected-approved');
        $this->assertSame(65.0, $subscription->balance());
        $bills = $subscription->transactions()->where('type', SubscriptionTransaction::TYPE_METER_READING)->orderBy('id')->get();
        $this->assertSame(['60.00', '55.00', '65.00'], $bills->pluck('amount')->all());
        $this->assertSame([null, $bills[0]->id, $bills[1]->id], $bills->pluck('corrects_id')->all());
        $this->assertSame([true, true, false], $bills->map->isCancelled()->all());
        $this->assertSame('130.00', $bills[2]->billed_consumption);

        $this->travelTo('2026-10-08 08:00:00');
        $this->actingAs($head)->put(route('subscriptions.standing-discount.update', $subscription), ['method' => 'percentage', 'value' => '20', 'segment' => 'أسر محتاجة'])->assertSessionHasNoErrors();
        $this->record($dataEntry, $subscription, '2026-10-02', 1150)->assertSessionHasNoErrors();
        $second = MeterReading::query()->whereDate('week_start', '2026-10-02')->sole();
        $this->actingAs($head)->delete(route('subscriptions.standing-discount.destroy', $subscription))->assertSessionHasNoErrors();
        $this->approve($head, $second)->assertSessionHasNoErrors();
        $this->assertSame(1130.0, $second->previous_reading);
        $this->assertSame(['10.00', '-2.00'], $subscription->transactions()->where('meter_reading_id', $second->id)->orderBy('id')->pluck('amount')->all());
        $this->assertSame(73.0, $subscription->balance());
        $this->assertFalse($dataEntry->can('update', $first->fresh()));

        $this->travelTo('2026-10-15 08:00:00');
        $this->record($dataEntry, $subscription, '2026-10-09', 3650)->assertSessionHas('status', 'meter-reading-created-unusual');
        $third = MeterReading::query()->whereDate('week_start', '2026-10-09')->sole();
        $this->approve($head, $third)->assertSessionHasErrors('reading_ids');
        $this->assertSame(MeterReadingStatus::Pending, $third->fresh()->status);
        $this->approve($head, $third, confirmUnusual: true)->assertSessionHasNoErrors();
        $this->assertSame(1323.0, $subscription->balance());
        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), ['amount' => '50', 'currency' => 'ILS', 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $thirdBill = $third->chargeLine()->sole();
        $this->actingAs($head)->post(route('subscriptions.transactions.actions.store', [$subscription, $thirdBill]), [
            'action' => 'cancel', 'correction_reason' => 'wrong_reading', 'correction_notes' => 'خطأ في كتابة القراءة', 'reopen_reading' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame(MeterReadingStatus::Pending, $third->fresh()->status);
        $this->assertSame(23.0, $subscription->balance());
        $this->correct($dataEntry, $third, 1170)->assertSessionHasNoErrors();
        $this->approve($head, $third)->assertSessionHasNoErrors();
        $this->assertSame(33.0, $subscription->balance());

        $this->travelTo('2026-10-22 08:00:00');
        $this->record($dataEntry, $subscription, '2026-10-16', 1180)->assertSessionHasNoErrors();
        $fourth = MeterReading::query()->whereDate('week_start', '2026-10-16')->sole();
        $this->approve($head, $fourth)->assertSessionHasNoErrors();
        $this->assertSame(43.0, $subscription->balance());
        $this->actingAs($head)->delete(route('subscriptions.transactions.destroy', [$subscription, $fourth->chargeLine()->sole()]), [
            'correction_reason' => 'wrong_reading', 'correction_notes' => 'إعفاء الأسبوع',
        ])->assertSessionHasNoErrors();
        $this->approve($head, $fourth)->assertSessionHasErrors('reading_ids');

        $this->assertSame(MeterReadingStatus::Approved, $fourth->fresh()->status);
        $this->assertSame(33.0, $subscription->balance());
        $this->assertSame(1180.0, $subscription->previousReadingBefore(now()->addWeek()));
        $this->actingAs($head)->get(route('subscriptions.statement', $subscription))->assertInertia(fn ($page) => $page
            ->where('summary.balance', '33.00')
            ->where('summary.charged', '85.00')
            ->where('summary.discounted', '2.00')
            ->where('summary.paid', '50.00')
            ->where('entries', function ($entries): bool {
                $waived = $entries->last(fn (array $entry): bool => $entry['type'] === SubscriptionTransaction::TYPE_METER_READING);
                $this->assertSame('إعفاء الأسبوع', $waived['cancellation']['notes']);
                $this->assertSame([], $waived['available_actions']);

                return true;
            }));
    }

    private function record(User $actor, Subscription $subscription, string $weekStart, float $currentReading): TestResponse
    {
        return $this->actingAs($actor)->post(route('meter-readings.store'), [
            'subscription_id' => $subscription->id, 'week_start' => $weekStart, 'current_reading' => $currentReading,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function correct(User $actor, MeterReading $reading, float $currentReading, array $extra = []): TestResponse
    {
        return $this->actingAs($actor)->put(route('meter-readings.update', $reading), ['current_reading' => $currentReading, ...$extra]);
    }

    private function approve(User $actor, MeterReading $reading, bool $confirmUnusual = false): TestResponse
    {
        return $this->actingAs($actor)->post(route('meter-readings.approve'), ['reading_ids' => [$reading->id], 'confirm_unusual' => $confirmUnusual]);
    }
}
