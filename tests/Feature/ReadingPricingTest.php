<?php

namespace Tests\Feature;

use App\Enums\DiscountMethod;
use App\Enums\MeterReadingStatus;
use App\Enums\PermissionKey;
use App\Models\MeterReading;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ReadingPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-24 10:00:00');
    }

    public function test_price_permission_edits_the_latest_reading_without_granting_reading_correction_or_changing_subscriber_defaults(): void
    {
        [$actor, $subscription, $reading] = $this->context(PermissionKey::UpdateSubscriptionKilowattPrice);
        $reading->approve($actor);
        $this->assertFalse($actor->can('update', $reading));
        $this->assertTrue($actor->can('updateUnitPrice', $reading));
        $this->assertFalse($actor->can('updateMinimumPayment', $reading));

        $this->actingAs($actor)->put(route('meter-readings.pricing', $reading), ['unit_price' => 2])->assertSessionHasNoErrors();

        $reading->refresh();
        $this->assertSame(['2.00', '20.00', MeterReadingStatus::Pending], [$reading->unit_price, $reading->amount_due, $reading->status]);
        $this->assertSame('0.50', $subscription->fresh()->kilowatt_price);
        $this->assertSame(0.0, $subscription->fresh()->balance());
        $this->assertSame('0.50', $reading->adjustment_history[0]['before']['unit_price']);
        $this->assertSame('2.00', $reading->adjustment_history[0]['after']['unit_price']);
        $this->assertSame($actor->id, $reading->adjustment_history[0]['userId']);
        $this->assertTrue($reading->approve($actor));
        $this->assertSame(20.0, $subscription->fresh()->balance());
    }

    public function test_minimum_permission_changes_only_the_minimum_and_cannot_change_reading_or_price(): void
    {
        [$actor, $subscription, $reading] = $this->context(PermissionKey::UpdateSubscriptionMinimumCharge);
        $this->actingAs($actor)->put(route('meter-readings.pricing', $reading), ['minimum_payment' => 12, 'current_reading' => 999])->assertSessionHasNoErrors();

        $this->assertSame(['12.00', '12.00', 210.0, '0.50'], [
            $reading->fresh()->minimum_payment, $reading->fresh()->amount_due, $reading->fresh()->current_reading, $reading->fresh()->unit_price,
        ]);
        $this->assertSame(0, $subscription->fresh()->minimum_charge);
        $this->put(route('meter-readings.pricing', $reading), ['unit_price' => 3, 'minimum_payment' => 15])->assertForbidden();
        $this->put(route('meter-readings.update', $reading), ['current_reading' => 220])->assertForbidden();
        $this->assertSame('12.00', $reading->fresh()->minimum_payment);
    }

    public function test_pricing_keeps_the_discount_captured_on_the_reading(): void
    {
        [$actor, $subscription, $reading] = $this->context(PermissionKey::UpdateSubscriptionKilowattPrice);
        $reading->update(['discount_method' => DiscountMethod::Percentage, 'discount_value' => 10]);

        $this->actingAs($actor)->put(route('meter-readings.pricing', $reading), ['unit_price' => 2])->assertSessionHasNoErrors();

        $this->assertSame(['20.00', '2.00', '18.00'], [$reading->fresh()->reading_fee, $reading->fresh()->discount_amount, $reading->fresh()->amount_due]);
    }

    public function test_minimum_charge_permissions_are_preserved_after_the_reading_day(): void
    {
        [$actor, $subscription, $reading] = $this->context(PermissionKey::UpdateSubscriptionMinimumCharge);
        $this->travelTo('2026-09-25 10:00:00');

        $this->assertFalse($actor->can('createOnReadingDay', MeterReading::class));
        $this->actingAs($actor)->put(route('meter-readings.pricing', $reading), ['minimum_payment' => 12])->assertSessionHasNoErrors();
        $this->put(route('meter-readings.pricing', $reading), ['unit_price' => 3])->assertForbidden();

        $this->assertSame(['12.00', '0.50', '12.00'], [$reading->fresh()->minimum_payment, $reading->fresh()->unit_price, $reading->fresh()->amount_due]);
        $this->assertSame(0, $subscription->fresh()->minimum_charge);
    }

    public function test_old_rows_foreign_branches_and_waiting_current_readings_are_locked_even_for_super_admins(): void
    {
        [$actor, $subscription, $reading] = $this->context(PermissionKey::UpdateSubscriptionKilowattPrice);
        $foreign = User::factory()->superAdmin()->create();
        $reading->update(['week_start' => '2026-09-11', 'week_end' => '2026-09-17']);
        $this->actingAs($foreign)->put(route('meter-readings.pricing', $reading), ['unit_price' => 3])->assertForbidden();
        $reading->update(['week_start' => '2026-09-18', 'week_end' => '2026-09-24', 'requires_reentry' => true]);
        $this->put(route('meter-readings.pricing', $reading), ['unit_price' => 3])->assertForbidden();
        $reading->update(['requires_reentry' => false]);
        $otherBranch = User::factory()->branchAdmin()->create();
        $otherBranch->permissions()->syncWithoutDetaching(Permission::idsFor([PermissionKey::UpdateSubscriptionKilowattPrice]));
        $this->actingAs($otherBranch)->put(route('meter-readings.pricing', $reading), ['unit_price' => 3])->assertForbidden();

        $this->assertSame('0.50', $reading->fresh()->unit_price);
    }

    #[TestWith([null])]
    #[TestWith([-1])]
    #[TestWith([10001])]
    #[TestWith([0.001])]
    public function test_invalid_prices_do_not_change_the_bill(?float $value): void
    {
        [$actor, $subscription, $reading] = $this->context(PermissionKey::UpdateSubscriptionKilowattPrice);
        $this->actingAs($actor)->put(route('meter-readings.pricing', $reading), ['unit_price' => $value])->assertSessionHasErrors('unit_price');
        $this->assertSame(['0.50', '5.00'], [$reading->fresh()->unit_price, $reading->fresh()->amount_due]);
    }

    public function test_price_controls_are_exposed_separately_and_auditors_cannot_write(): void
    {
        [$actor, $subscription, $reading] = $this->context(PermissionKey::UpdateSubscriptionKilowattPrice);
        $this->actingAs($actor)->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page
            ->where('subscriptions.data.0.meterReadings.0.canUpdateUnitPrice', true)
            ->where('subscriptions.data.0.meterReadings.0.canUpdateMinimumPayment', false)
            ->where('subscriptions.data.0.meterReadings.0.canUpdate', false));

        $this->actingAs(User::factory()->financialAuditor()->create())->put(route('meter-readings.pricing', $reading), ['unit_price' => 3])->assertForbidden();
        $actor->permissions()->sync([]);
        $this->actingAs($actor->fresh())->put(route('meter-readings.pricing', $reading), ['unit_price' => 3])->assertForbidden();
    }

    public function test_unauthenticated_pricing_edits_redirect_to_login(): void
    {
        [$actor, $subscription, $reading] = $this->context(PermissionKey::UpdateSubscriptionKilowattPrice);
        $this->put(route('meter-readings.pricing', $reading), ['unit_price' => 3])->assertRedirect(route('login'));
    }

    /** @return array{User, Subscription, MeterReading} */
    private function context(PermissionKey $permission): array
    {
        $actor = User::factory()->dataEntry()->create();
        $actor->permissions()->sync(Permission::idsFor([PermissionKey::ViewSubscriptions, $permission]));
        $subscription = Subscription::factory()->for($actor->branch)->create(['kilowatt_price' => '0.50', 'minimum_charge' => 0]);
        $reading = MeterReading::factory()->for($subscription)->create([
            'week_start' => '2026-09-18', 'week_end' => '2026-09-24',
            'previous_reading' => 200, 'current_reading' => 210, 'consumption' => 10,
            'unit_price' => '0.50', 'minimum_payment' => 0, 'reading_fee' => 5, 'amount_due' => 5,
        ]);

        return [$actor, $subscription, $reading];
    }
}
