<?php

namespace Tests\Feature;

use App\Enums\MeterReadingStatus;
use App\Enums\PermissionKey;
use App\Models\MeterReading;
use App\Models\Permission;
use App\Models\ReadingEntrySetting;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ReadingAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-24 10:00:00');
    }

    public function test_a_normal_correction_rebills_the_previous_week_and_waits_for_current_reading_in_the_table(): void
    {
        [$actor, $subscription, $last] = $this->context();
        $current = $this->reading($subscription, '2026-09-18', 210, 230, $actor);
        $oldCurrentCharge = $current->chargeLine;

        $this->actingAs($actor)->put(route('meter-readings.adjust-last', $last), [
            'adjustment_type' => 'correction', 'current_reading' => 205, 'expected_reading' => 210,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame([205.0, 5.0, '2.50', MeterReadingStatus::Approved], [
            $last->fresh()->current_reading, $last->fresh()->consumption, $last->fresh()->amount_due, $last->fresh()->status,
        ]);
        $this->assertSame([true, 205.0, MeterReadingStatus::Pending, '0.00'], [
            $current->fresh()->requires_reentry, $current->fresh()->previous_reading, $current->fresh()->status, $current->fresh()->amount_due,
        ]);
        $this->assertSame(230, $current->fresh()->adjustment_history[0]['before']['currentReading']);
        $this->assertSame(SubscriptionTransaction::STATUS_CANCELLED, $oldCurrentCharge->fresh()->status);
        $this->assertFalse($current->fresh()->approve($actor, true));
        $this->assertSame(2.5, $subscription->fresh()->balance());
        $this->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page
            ->where('subscriptions.data.0.meterReadings.0.requiresReentry', true)
            ->where('subscriptions.data.0.meterReadings.0.canUpdateUnitPrice', false));
        $this->get(route('meter-readings.index'))->assertInertia(fn ($page) => $page
            ->where('rows.data.0.reading.requiresReentry', true)->where('rows.data.0.canApprove', false));

        $this->put(route('meter-readings.update', $current), ['current_reading' => 225])->assertSessionHasNoErrors();
        $this->assertSame([false, 20.0, '10.00'], [$current->fresh()->requires_reentry, $current->fresh()->consumption, $current->fresh()->amount_due]);
        $this->assertTrue($current->fresh()->approve($actor));
        $this->assertSame(12.5, $subscription->fresh()->balance());
    }

    public function test_a_reset_preserves_the_old_bill_and_starts_the_next_week_from_zero(): void
    {
        [$actor, $subscription, $last] = $this->context();
        $oldBill = array_intersect_key($last->getAttributes(), array_flip(['previous_reading', 'current_reading', 'consumption', 'amount_due', 'status', 'approved_by', 'approved_at']));
        $transactions = $subscription->transactions()->get()->map->getAttributes()->all();

        $this->actingAs($actor)->postJson(route('meter-readings.adjustment-preview', $last), [
            'adjustment_type' => 'meter_change', 'current_reading' => 0, 'expected_reading' => 210, 'reason' => 'عداد جديد',
        ])->assertOk()->assertJsonPath('reset', true)->assertJsonPath('weeks.0.after.amountDue', '5.00');

        $this->put(route('meter-readings.adjust-last', $last), [
            'adjustment_type' => 'meter_change', 'current_reading' => 0, 'expected_reading' => 210, 'reason' => 'استبدال العداد التالف',
        ])->assertSessionHasNoErrors();

        $this->assertSame($oldBill, array_intersect_key($last->fresh()->getAttributes(), $oldBill));
        $this->assertSame($transactions, $subscription->transactions()->get()->map->getAttributes()->all());
        $this->assertSame(0.0, $last->fresh()->nextPreviousReading());
        $history = $last->fresh()->adjustment_history[0];
        $this->assertSame('استبدال العداد التالف', $history['reason']);
        $this->assertSame($actor->id, $history['userId']);
        $this->assertSame(210, $history['before']['previousReading']);
        $this->assertSame(0, $history['after']['previousReading']);
        $this->assertNotEmpty($history['at']);
        $this->get(route('meter-readings.index'))->assertInertia(fn ($page) => $page->where('rows.data.0.previousReading', 0));

        $this->post(route('meter-readings.store'), [
            'subscription_id' => $subscription->id, 'week_start' => '2026-09-18', 'current_reading' => 30,
        ])->assertSessionHasNoErrors();
        $next = $subscription->meterReadings()->whereDate('week_start', '2026-09-18')->sole();
        $this->assertSame([0.0, 30.0, '15.00'], [$next->previous_reading, $next->consumption, $next->amount_due]);
    }

    public function test_resetting_with_an_existing_current_reading_keeps_the_last_bill_and_requires_a_new_current_entry(): void
    {
        [$actor, $subscription, $last] = $this->context();
        $oldCharge = $last->chargeLine->getAttributes();
        $current = $this->reading($subscription, '2026-09-18', 210, 230, $actor);

        $this->actingAs($actor)->put(route('meter-readings.adjust-last', $last), [
            'adjustment_type' => 'meter_change', 'current_reading' => 0, 'expected_reading' => 210, 'reason' => 'عداد مسروق',
        ])->assertSessionHasNoErrors();

        $this->assertSame($oldCharge, $last->fresh()->chargeLine->getAttributes());
        $this->assertSame(5.0, $subscription->fresh()->balance());
        $this->assertTrue($current->fresh()->requires_reentry);
        $this->assertSame(0.0, $current->fresh()->previous_reading);

        $this->travelTo('2026-10-01 10:00:00');
        $this->post(route('meter-readings.store'), [
            'subscription_id' => $subscription->id, 'week_start' => '2026-09-25', 'current_reading' => 240,
        ])->assertSessionHasErrors(['current_reading' => 'أدخل القراءة الحالية المعلّقة أولًا قبل تسجيل أسبوع جديد.']);
    }

    public function test_a_reset_requires_a_reason_and_a_stale_baseline_is_refused(): void
    {
        [$actor, $subscription, $last] = $this->context();

        $this->actingAs($actor)->put(route('meter-readings.adjust-last', $last), [
            'adjustment_type' => 'meter_change', 'current_reading' => 0, 'expected_reading' => 210, 'reason' => '   ',
        ])->assertSessionHasErrors(['reason' => 'اكتب سبب تغيير العداد، مثل السرقة أو التلف أو الاستبدال.']);
        $this->put(route('meter-readings.adjust-last', $last), [
            'adjustment_type' => 'correction', 'current_reading' => 205, 'expected_reading' => 209,
        ])->assertSessionHasErrors(['current_reading' => 'تغيّرت القراءة؛ حدّث الصفحة وراجع القيمة الجديدة قبل التعديل.']);

        $this->assertNull($last->fresh()->next_previous_reading);
        $this->assertNull($last->fresh()->adjustment_history);
        $this->assertSame(5.0, $subscription->balance());
    }

    #[TestWith([205, false])]
    #[TestWith([200, false])]
    public function test_an_entry_correction_keeps_the_correction_workflow(float $value, bool $reset): void
    {
        [$actor, $subscription, $last] = $this->context();

        $this->actingAs($actor)->postJson(route('meter-readings.adjustment-preview', $last), [
            'adjustment_type' => 'correction', 'current_reading' => $value, 'expected_reading' => 210,
        ])->assertOk()->assertJsonPath('reset', $reset);

        $this->assertSame(210.0, $last->fresh()->current_reading);
        $this->assertSame(5.0, $subscription->balance());
    }

    #[TestWith([199])]
    #[TestWith([0])]
    public function test_a_correction_below_the_period_start_is_refused_instead_of_silently_changing_the_meter(float $value): void
    {
        [$actor, $subscription, $last] = $this->context();
        $this->actingAs($actor)->postJson(route('meter-readings.adjustment-preview', $last), [
            'adjustment_type' => 'correction', 'current_reading' => $value, 'expected_reading' => 210,
        ])->assertUnprocessable()->assertJsonValidationErrors('current_reading');
        $this->put(route('meter-readings.adjust-last', $last), [
            'adjustment_type' => 'correction', 'current_reading' => $value, 'expected_reading' => 210,
        ])->assertSessionHasErrors(['current_reading' => 'تصحيح القراءة لا يمكن أن يقل عن بداية الفترة (200). عند تغيير العداد اختر «عداد جديد / مستبدل».']);
        $this->assertNull($last->fresh()->next_previous_reading);
        $this->assertSame(5.0, $subscription->balance());
    }

    #[TestWith([205])]
    #[TestWith([210])]
    #[TestWith([500])]
    public function test_the_selected_meter_change_preserves_the_bill_even_when_the_new_meter_has_a_higher_reading(float $value): void
    {
        [$actor, $subscription, $last] = $this->context();
        $payload = ['adjustment_type' => 'meter_change', 'current_reading' => $value, 'expected_reading' => 210, 'reason' => 'تركيب عداد بديل'];
        $this->actingAs($actor)->postJson(route('meter-readings.adjustment-preview', $last), $payload)
            ->assertOk()->assertJsonPath('reset', true)->assertJsonPath('weeks.0.after.amountDue', '5.00');
        $this->put(route('meter-readings.adjust-last', $last), $payload)->assertSessionHasNoErrors();

        $this->assertSame($value, $last->fresh()->nextPreviousReading());
        $this->assertSame(210.0, $last->fresh()->current_reading);
        $this->assertSame('تركيب عداد بديل', $last->fresh()->adjustment_history[0]['reason']);
        $this->assertSame(5.0, $subscription->balance());
    }

    public function test_the_adjustment_purpose_must_be_selected_and_meter_changes_require_a_reason_before_previewing(): void
    {
        [$actor, $subscription, $last] = $this->context();
        $this->actingAs($actor)->postJson(route('meter-readings.adjustment-preview', $last), [
            'current_reading' => 205, 'expected_reading' => 210,
        ])->assertUnprocessable()->assertJsonValidationErrors('adjustment_type');
        $this->put(route('meter-readings.adjust-last', $last), [
            'adjustment_type' => 'other', 'current_reading' => 205, 'expected_reading' => 210,
        ])->assertSessionHasErrors('adjustment_type');
        $this->postJson(route('meter-readings.adjustment-preview', $last), [
            'adjustment_type' => 'meter_change', 'current_reading' => 205, 'expected_reading' => 210,
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertNull($last->fresh()->adjustment_history);
        $this->assertSame(5.0, $subscription->balance());
    }

    public function test_an_old_current_charge_cannot_be_reinstated_while_its_reading_is_awaiting_reentry(): void
    {
        [$actor, $subscription, $last] = $this->context();
        $current = $this->reading($subscription, '2026-09-18', 210, 230, $actor);
        $charge = $current->chargeLine;
        $this->actingAs($actor)->put(route('meter-readings.adjust-last', $last), [
            'adjustment_type' => 'correction', 'current_reading' => 205, 'expected_reading' => 210,
        ])->assertSessionHasNoErrors();
        $reversal = $subscription->transactions()->where('reverses_id', $charge->id)->sole();

        $this->actingAs(User::factory()->superAdmin()->create())->post(route('subscriptions.transactions.actions.store', [$subscription, $reversal]), [
            'action' => 'delete_reversal', 'correction_notes' => 'محاولة إعادة المبلغ السابق',
        ])->assertSessionHasErrors(['action' => 'أدخل القراءة الحالية من جديد قبل إعادة مبلغ القراءة السابق.']);

        $this->assertModelExists($reversal);
        $this->assertTrue($current->fresh()->requires_reentry);
        $this->assertSame(SubscriptionTransaction::STATUS_CANCELLED, $charge->fresh()->status);
    }

    public function test_discount_changes_cannot_bill_an_empty_current_reading(): void
    {
        [$actor, $subscription, $last] = $this->context();
        $current = $this->reading($subscription, '2026-09-18', 210, 230, $actor);
        $current->update(['minimum_payment' => 20]);
        $this->actingAs($actor)->put(route('meter-readings.adjust-last', $last), [
            'adjustment_type' => 'correction', 'current_reading' => 205, 'expected_reading' => 210,
        ])->assertSessionHasNoErrors();

        $current->fresh()->applyStandingDiscount(null, $actor);

        $this->assertSame('0.00', $current->fresh()->amount_due);
        $this->assertTrue($current->fresh()->requires_reentry);
        $this->assertFalse($current->fresh()->approve($actor, true));
    }

    #[TestWith([-1])]
    #[TestWith([0.001])]
    #[TestWith([10000000000])]
    public function test_invalid_reading_values_never_change_the_bill(float $value): void
    {
        [$actor, $subscription, $last] = $this->context();

        $this->actingAs($actor)->put(route('meter-readings.adjust-last', $last), [
            'adjustment_type' => 'correction', 'current_reading' => $value, 'expected_reading' => 210, 'reason' => 'عداد جديد',
        ])->assertSessionHasErrors('current_reading');

        $this->assertSame(210.0, $last->fresh()->current_reading);
        $this->assertSame(5.0, $subscription->balance());
    }

    public function test_adjusting_requires_the_previous_reading_permission_and_branch_and_latest_row(): void
    {
        [$actor, $subscription, $last] = $this->context();
        $foreign = User::factory()->branchAdmin()->create();
        $this->actingAs($foreign)->put(route('meter-readings.adjust-last', $last), [
            'adjustment_type' => 'correction', 'current_reading' => 205, 'expected_reading' => 210,
        ])->assertForbidden();

        $actor->permissions()->detach(Permission::idsFor([PermissionKey::CorrectLastMeterReadings]));
        $this->actingAs($actor->fresh())->put(route('meter-readings.adjust-last', $last), [
            'adjustment_type' => 'correction', 'current_reading' => 205, 'expected_reading' => 210,
        ])->assertForbidden();
        $this->assertSame(210.0, $last->fresh()->current_reading);

        $admin = User::factory()->superAdmin()->create();
        $this->reading($subscription, '2026-09-18', 210, 230, $admin);
        $this->reading($subscription, '2026-09-25', 230, 240, $admin);
        $this->actingAs($admin)->put(route('meter-readings.adjust-last', $last), [
            'adjustment_type' => 'correction', 'current_reading' => 205, 'expected_reading' => 210,
        ])->assertForbidden();
    }

    public function test_an_unusual_correction_rolls_back_until_confirmed(): void
    {
        [$actor, $subscription, $last] = $this->context();
        config(['powercollect.readings.unusual_without_history_kwh' => 15]);

        $this->actingAs($actor)->put(route('meter-readings.adjust-last', $last), [
            'adjustment_type' => 'correction', 'current_reading' => 220, 'expected_reading' => 210,
        ])->assertSessionHasErrors('confirm_unusual');

        $this->assertSame(210.0, $last->fresh()->current_reading);
        $this->assertNull($last->fresh()->adjustment_history);
        $this->assertSame(5.0, $subscription->balance());
    }

    public function test_unauthenticated_and_auditor_requests_cannot_adjust_readings(): void
    {
        [$actor, $subscription, $last] = $this->context();
        $payload = ['adjustment_type' => 'correction', 'current_reading' => 205, 'expected_reading' => 210];
        $this->put(route('meter-readings.adjust-last', $last), $payload)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->financialAuditor()->create())->put(route('meter-readings.adjust-last', $last), $payload)->assertForbidden();
    }

    #[TestWith(['meter-readings.update', 'put'])]
    #[TestWith(['meter-readings.last-reading-preview', 'postJson'])]
    #[TestWith(['meter-readings.adjustment-preview', 'postJson'])]
    #[TestWith(['meter-readings.adjust-last', 'put'])]
    public function test_even_the_super_admin_cannot_edit_an_older_reading_when_later_weeks_are_missing(string $routeName, string $method): void
    {
        $actor = User::factory()->superAdmin()->create();
        $subscription = Subscription::factory()->create();
        $old = $this->reading($subscription, '2026-09-04', 200, 210, $actor);
        $readingBefore = $old->getAttributes();
        $transactionsBefore = $subscription->transactions()->get()->map->getAttributes()->all();

        $this->actingAs($actor)->{$method}(route($routeName, $old), [
            'adjustment_type' => 'correction', 'current_reading' => 205, 'expected_reading' => 210,
            'reason' => 'استبدال العداد', 'as_last_reading' => true,
        ])->assertForbidden();

        $this->assertSame($readingBefore, $old->fresh()->getAttributes());
        $this->assertSame($transactionsBefore, $subscription->transactions()->get()->map->getAttributes()->all());
        $this->assertSame(5.0, $subscription->fresh()->balance());
    }

    #[TestWith(['2026-09-04', false, false])]
    #[TestWith(['2026-09-11', true, false])]
    #[TestWith(['2026-09-18', false, true])]
    #[TestWith(['2026-09-25', false, false])]
    public function test_super_admin_edit_permissions_follow_the_branch_week_instead_of_overriding_it(string $week, bool $canEditPrevious, bool $canEditCurrent): void
    {
        $actor = User::factory()->superAdmin()->create();
        $subscription = Subscription::factory()->create();
        $reading = $this->reading($subscription, $week, 200, 210, $actor);

        $this->assertSame($canEditCurrent, $actor->can('update', $reading));
        $this->assertSame($canEditPrevious, $actor->can('updateAsLastReading', $reading));
        $this->assertSame($canEditPrevious, $actor->can('adjustLastReading', $reading));
        $this->assertTrue($actor->can('view', $reading));
    }

    public function test_the_previous_reading_window_rolls_forward_for_the_super_admin_without_reopening_older_bills(): void
    {
        [, $subscription, $last] = $this->context();
        $actor = User::factory()->superAdmin()->create();
        $current = $this->reading($subscription, '2026-09-18', 210, 230, $actor);
        $lastBefore = $last->getAttributes();
        $transactionsBefore = $subscription->transactions()->get()->map->getAttributes()->all();

        $this->assertTrue($actor->can('update', $current));
        $this->assertTrue($actor->can('adjustLastReading', $last));

        $this->travelTo('2026-10-01 10:00:00');

        $this->assertFalse($actor->can('update', $current));
        $this->assertFalse($actor->can('adjustLastReading', $last));

        $this->actingAs($actor)->put(route('meter-readings.adjust-last', $last), [
            'adjustment_type' => 'meter_change', 'current_reading' => 0, 'expected_reading' => 210,
            'reason' => 'استبدال العداد',
        ])->assertForbidden();

        $this->put(route('meter-readings.adjust-last', $current), [
            'adjustment_type' => 'meter_change', 'current_reading' => 0, 'expected_reading' => 230,
            'reason' => 'استبدال العداد',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($lastBefore, $last->fresh()->getAttributes());
        $this->assertSame(0.0, $current->fresh()->nextPreviousReading());
        $this->assertSame($transactionsBefore, $subscription->transactions()->get()->map->getAttributes()->all());
        $this->assertSame(15.0, $subscription->fresh()->balance());
    }

    public function test_an_older_reading_is_viewable_but_not_offered_for_editing_to_the_super_admin(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $subscription = Subscription::factory()->create();
        $old = $this->reading($subscription, '2026-09-04', 200, 210, $actor);

        $this->actingAs($actor)->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page
            ->where('subscriptions.data.0.editableLastReadingId', null)
            ->where('subscriptions.data.0.meterReadings.0.id', $old->id));

        $this->get(route('meter-readings.index', ['week' => '2026-09-04']))->assertInertia(fn ($page) => $page
            ->where('week', '2026-09-04')
            ->where('rows.data.0.reading.id', $old->id)
            ->where('rows.data.0.canEdit', false));
    }

    public function test_the_super_admins_previous_reading_limit_uses_the_subscriptions_branch_schedule(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $subscription = Subscription::factory()->create();
        ReadingEntrySetting::factory()->forBranch($subscription->branch)->forcedOpen()->create(['reading_day' => CarbonInterface::SATURDAY]);
        $last = $this->reading($subscription, '2026-09-06', 200, 210, $actor);
        $older = $this->reading($subscription, '2026-08-30', 190, 200, $actor);

        $this->assertTrue($actor->can('adjustLastReading', $last));
        $this->assertFalse($actor->can('update', $last));
        $this->assertFalse($actor->can('updateAsLastReading', $older));
        $this->assertFalse($actor->can('adjustLastReading', $older));
    }

    /** @return array{User, Subscription, MeterReading} */
    private function context(): array
    {
        $actor = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->for($actor->branch)->create(['kilowatt_price' => '0.50', 'minimum_charge' => 0]);
        $last = $this->reading($subscription, '2026-09-11', 200, 210, $actor);

        return [$actor, $subscription, $last];
    }

    private function reading(Subscription $subscription, string $week, float $previous, float $current, User $actor): MeterReading
    {
        $reading = MeterReading::factory()->for($subscription)->create([
            'week_start' => $week, 'week_end' => now()->parse($week)->addDays(6),
            'previous_reading' => $previous, 'current_reading' => $current, 'consumption' => $current - $previous,
            'unit_price' => '0.50', 'minimum_payment' => '0.00',
            ...MeterReading::chargesFor($current - $previous, '0.50', '0.00'),
        ]);
        $reading->approve($actor, true);

        return $reading;
    }
}
