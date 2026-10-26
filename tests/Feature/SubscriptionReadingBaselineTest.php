<?php

namespace Tests\Feature;

use App\Enums\DiscountMethod;
use App\Enums\PermissionKey;
use App\Models\MeterReading;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SubscriptionReadingBaselineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-24 10:00:00');
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [...[
            'week_start' => '2026-09-18', 'expected_reading' => 0, 'current_reading' => 123,
            'adjustment_type' => 'correction',
        ], ...$overrides];
    }

    public function test_zero_initial_readings_offer_a_pencil_and_can_be_previewed_then_saved_without_a_fake_historical_week(): void
    {
        $actor = User::factory()->dataEntry()->create();
        $subscription = Subscription::factory()->for($actor->branch)->create(['initial_reading' => 0]);
        $this->actingAs($actor)->get(route('meter-readings.index'))
            ->assertInertia(fn ($page) => $page->where('rows.data.0.initialReading.canEdit', true)->where('rows.data.0.previousReading', 0));
        $this->get(route('subscriptions.index'))
            ->assertInertia(fn ($page) => $page->where('subscriptions.data.0.initialReading.canEdit', true)->where('subscriptions.data.0.initialReading.value', 0));

        $this->postJson(route('subscriptions.reading-baseline.preview', $subscription), $this->payload())
            ->assertOk()->assertJsonPath('before', 0)->assertJsonPath('after', 123)->assertJsonPath('clearsCurrentReading', false);
        $this->assertSame(0.0, $subscription->fresh()->initial_reading);
        $this->put(route('subscriptions.reading-baseline.update', $subscription), $this->payload())->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(123.0, $subscription->fresh()->initial_reading);
        $this->assertDatabaseCount('meter_readings', 0);
        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_changing_the_first_readings_baseline_reverses_its_bill_and_requires_the_current_reading_again(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->for($actor->branch)->create(['initial_reading' => 123, 'kilowatt_price' => 0.5, 'minimum_charge' => 0]);
        $current = MeterReading::recordFor($subscription, now()->parse('2026-09-18'), 150, $actor, null);
        $current->approve($actor, true);
        $payload = $this->payload(['expected_reading' => 123, 'current_reading' => 130]);

        $this->actingAs($actor)->postJson(route('subscriptions.reading-baseline.preview', $subscription), $payload)
            ->assertJsonPath('clearsCurrentReading', true)->assertJsonPath('removedCurrentAmount', '13.50');
        $this->assertSame(13.5, $subscription->fresh()->balance());
        $this->put(route('subscriptions.reading-baseline.update', $subscription), $payload)->assertSessionHasNoErrors();

        $this->assertSame(130.0, $subscription->fresh()->initial_reading);
        $this->assertSame(130.0, $current->fresh()->previous_reading);
        $this->assertTrue($current->fresh()->requires_reentry);
        $this->assertSame(0.0, $subscription->fresh()->balance());
        $this->assertSame(1, $subscription->meterReadings()->count());
        $this->assertSame('initial_reading_adjustment', $current->fresh()->adjustment_history[0]['kind']);
        $this->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page
            ->where('subscriptions.data.0.initialReading.value', 130)
            ->where('subscriptions.data.0.meterReadings.0.requiresReentry', true)
            ->where('subscriptions.data.0.meterReadings.0.canApprove', false));
    }

    #[TestWith(['subscriptions.reading-baseline.preview', 'postJson'])]
    #[TestWith(['subscriptions.reading-baseline.update', 'put'])]
    public function test_the_initial_baseline_window_closes_even_for_the_super_admin_when_the_week_rolls_forward(string $routeName, string $method): void
    {
        $actor = User::factory()->superAdmin()->create();
        $subscription = Subscription::factory()->create(['initial_reading' => 0]);
        $this->travelTo('2026-10-01 10:00:00');

        $this->actingAs($actor)->{$method}(route($routeName, $subscription), $this->payload())->assertForbidden();

        $this->assertSame(0.0, $subscription->fresh()->initial_reading);
        $this->assertDatabaseCount('meter_readings', 0);
    }

    public function test_initial_baseline_cannot_bypass_the_lock_on_existing_historical_readings(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $subscription = Subscription::factory()->create(['initial_reading' => 0]);
        $historical = MeterReading::factory()->for($subscription)->create(['week_start' => '2026-09-04', 'previous_reading' => 100, 'current_reading' => 123, 'consumption' => 23]);
        $before = $historical->refresh()->getAttributes();

        $this->actingAs($actor)->put(route('subscriptions.reading-baseline.update', $subscription), $this->payload())->assertSessionHasErrors('current_reading');

        $this->assertSame(0.0, $subscription->fresh()->initial_reading);
        $this->assertSame($before, $historical->fresh()->getAttributes());
    }

    public function test_the_baseline_requires_the_previous_reading_permission_and_the_same_branch(): void
    {
        $actor = User::factory()->dataEntry()->create();
        $subscription = Subscription::factory()->for($actor->branch)->create(['initial_reading' => 0]);
        $this->put(route('subscriptions.reading-baseline.update', $subscription), $this->payload())->assertRedirect(route('login'));
        $actor->permissions()->detach(Permission::idsFor([PermissionKey::CorrectLastMeterReadings]));
        $this->actingAs($actor)->put(route('subscriptions.reading-baseline.update', $subscription), $this->payload())->assertForbidden();

        $this->actingAs(User::factory()->dataEntry()->create())->put(route('subscriptions.reading-baseline.update', $subscription), $this->payload())->assertForbidden();
        $this->assertSame(0.0, $subscription->fresh()->initial_reading);
    }

    public function test_a_stale_value_or_invalid_reading_cannot_overwrite_the_baseline(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->for($actor->branch)->create(['initial_reading' => 123]);

        $this->actingAs($actor)->put(route('subscriptions.reading-baseline.update', $subscription), $this->payload())->assertSessionHasErrors('current_reading');
        $this->postJson(route('subscriptions.reading-baseline.preview', $subscription), $this->payload(['expected_reading' => 123, 'current_reading' => -1]))
            ->assertUnprocessable()->assertJsonValidationErrors('current_reading');

        $this->assertSame(123.0, $subscription->fresh()->initial_reading);
    }

    public function test_archived_subscriptions_and_future_weeks_stay_locked_even_for_the_super_admin(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $subscription = Subscription::factory()->create(['initial_reading' => 123]);
        $this->actingAs($actor)->postJson(route('subscriptions.reading-baseline.preview', $subscription), $this->payload(['expected_reading' => 123, 'week_start' => '2026-09-25']))
            ->assertForbidden();
        $subscription->forceFill(['archived_at' => now()])->save();

        $this->put(route('subscriptions.reading-baseline.update', $subscription), $this->payload(['expected_reading' => 123]))->assertForbidden();

        $this->assertSame(123.0, $subscription->fresh()->initial_reading);
    }

    public function test_history_supplies_the_discount_captured_with_the_reading_for_live_corrections(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->for($actor->branch)->create(['initial_reading' => 100]);
        MeterReading::factory()->for($subscription)->create([
            'week_start' => '2026-09-18', 'previous_reading' => 100, 'current_reading' => 120,
            'consumption' => 20, 'unit_price' => 0.5, 'reading_fee' => 10, 'minimum_payment' => 0,
            'discount_method' => DiscountMethod::Percentage, 'discount_value' => 20, 'discount_amount' => 2,
            'amount_due' => 8, 'recorded_by' => $actor->id,
        ]);

        $this->actingAs($actor)->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page
            ->where('subscriptions.data.0.meterReadings.0.discount.method', 'percentage')
            ->where('subscriptions.data.0.meterReadings.0.discount.value', '20.00')
            ->where('subscriptions.data.0.meterReadings.0.canUpdate', true));
    }
}
