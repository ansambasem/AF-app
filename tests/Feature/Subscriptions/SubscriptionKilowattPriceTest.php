<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\DiscountMethod;
use App\Enums\PermissionKey;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\MeterReading;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SubscriptionKilowattPriceTest extends TestCase
{
    use RefreshDatabase;

    private Tariff $tariff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tariff = Tariff::factory()->commercial()->create(['rate' => 30]);
    }

    public function test_a_user_with_the_permission_gives_a_subscriber_their_own_kilowatt_price(): void
    {
        $this->actingAs($this->userWithKilowattPricePermission());

        $this->post(route('subscriptions.store'), $this->payload(['kilowatt_price' => 35.5]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('subscriptions.index'));

        $subscription = Subscription::sole();
        $this->assertSame('35.50', $subscription->kilowatt_price);
        $this->assertSame('35.50', $subscription->kilowattPrice());
        $this->assertTrue($subscription->hasOwnKilowattPrice());
    }

    public function test_the_super_admin_can_give_a_subscriber_their_own_kilowatt_price(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin);

        $this->post(route('subscriptions.store'), $this->payload(['kilowatt_price' => 40, 'branch_id' => Branch::factory()->create()->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame('40.00', Subscription::sole()->kilowatt_price);
    }

    public function test_a_price_equal_to_the_tariffs_is_not_stored_so_the_subscriber_keeps_following_the_tariff(): void
    {
        $this->actingAs($this->userWithKilowattPricePermission());

        $this->post(route('subscriptions.store'), $this->payload(['kilowatt_price' => 30]))
            ->assertSessionHasNoErrors();

        $subscription = Subscription::sole();
        $this->assertNull($subscription->kilowatt_price);
        $this->assertFalse($subscription->hasOwnKilowattPrice());

        $this->tariff->update(['rate' => 32]);
        $this->assertSame('32.00', $subscription->fresh()->kilowattPrice());
    }

    public function test_a_blank_price_leaves_the_subscriber_on_the_tariffs_price(): void
    {
        $this->actingAs($this->userWithKilowattPricePermission());

        $this->post(route('subscriptions.store'), $this->payload(['kilowatt_price' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull(Subscription::sole()->kilowatt_price);
        $this->assertSame('30.00', Subscription::sole()->kilowattPrice());
    }

    public function test_a_price_below_the_tariffs_is_accepted(): void
    {
        $this->actingAs($this->userWithKilowattPricePermission());

        $this->post(route('subscriptions.store'), $this->payload(['kilowatt_price' => 25]))
            ->assertSessionHasNoErrors();

        $subscription = Subscription::sole();
        $this->assertSame('25.00', $subscription->kilowatt_price);
        $this->assertSame('25.00', $subscription->kilowattPrice());
    }

    #[TestWith([-1])]
    #[TestWith([10000.01])]
    #[TestWith([30.555])]
    #[TestWith(['cheap'])]
    public function test_a_price_must_be_a_number_between_zero_and_the_limit_with_at_most_two_decimals(int|float|string $price): void
    {
        $this->actingAs($this->userWithKilowattPricePermission());

        $this->post(route('subscriptions.store'), $this->payload(['kilowatt_price' => $price]))
            ->assertSessionHasErrors('kilowatt_price');

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_a_user_without_the_permission_cannot_set_a_price_of_their_own(): void
    {
        $this->actingAs(User::factory()->dataEntry()->create());

        $this->post(route('subscriptions.store'), $this->payload(['kilowatt_price' => 99]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('subscriptions.index'));

        $this->assertNull(Subscription::sole()->kilowatt_price);
    }

    public function test_updating_changes_the_price_for_a_user_with_the_permission(): void
    {
        $user = $this->userWithKilowattPricePermission();
        $subscription = $this->subscriptionFor($user, ['kilowatt_price' => 35]);
        $this->actingAs($user);

        $this->put(route('subscriptions.update', $subscription), $this->payload($subscription, ['kilowatt_price' => 38]))
            ->assertSessionHasNoErrors();
        $this->assertSame('38.00', $subscription->fresh()->kilowatt_price);

        $this->put(route('subscriptions.update', $subscription), $this->payload($subscription, ['kilowatt_price' => '']))
            ->assertSessionHasNoErrors();
        $this->assertNull($subscription->fresh()->kilowatt_price);
    }

    public function test_updating_keeps_the_price_for_a_user_without_the_permission(): void
    {
        $user = User::factory()->dataEntry()->create();
        $subscription = $this->subscriptionFor($user, ['kilowatt_price' => 35]);
        $this->actingAs($user);

        $this->put(route('subscriptions.update', $subscription), $this->payload($subscription, ['kilowatt_price' => 99, 'notes' => 'Edited']))
            ->assertSessionHasNoErrors();

        $subscription->refresh();
        $this->assertSame('Edited', $subscription->notes);
        $this->assertSame('35.00', $subscription->kilowatt_price);
    }

    public function test_changing_the_tariff_drops_a_price_chosen_for_the_old_one_unless_the_permission_sets_a_new_one(): void
    {
        $dearerTariff = Tariff::factory()->residential()->create(['rate' => 50]);
        $withoutPermission = User::factory()->dataEntry()->create();
        $subscription = $this->subscriptionFor($withoutPermission, ['kilowatt_price' => 35]);

        $this->actingAs($withoutPermission)
            ->put(route('subscriptions.update', $subscription), $this->payload($subscription, ['tariff_id' => $dearerTariff->id]))
            ->assertSessionHasNoErrors();
        $this->assertNull($subscription->fresh()->kilowatt_price);

        $withPermission = $this->userWithKilowattPricePermission(['branch_id' => $withoutPermission->branch_id]);
        $this->actingAs($withPermission)
            ->put(route('subscriptions.update', $subscription), $this->payload($subscription, ['tariff_id' => $this->tariff->id, 'kilowatt_price' => 36]))
            ->assertSessionHasNoErrors();
        $this->assertSame('36.00', $subscription->fresh()->kilowatt_price);
    }

    public function test_a_reading_is_charged_at_the_subscribers_own_price(): void
    {
        $dataEntry = User::factory()->dataEntry()->create();
        $this->travelTo('2026-09-24 10:00:00');
        $subscription = $this->subscriptionFor($dataEntry, ['kilowatt_price' => 45, 'initial_reading' => 1200, 'minimum_charge' => 20]);

        $this->actingAs($dataEntry)
            ->post(route('meter-readings.store'), ['subscription_id' => $subscription->id, 'week_start' => '2026-09-18', 'current_reading' => 1250])
            ->assertSessionHasNoErrors();

        $reading = MeterReading::sole();
        $this->assertSame('45.00', $reading->unit_price);
        $this->assertSame('2250.00', $reading->reading_fee);
    }

    public function test_a_reading_for_a_subscriber_without_their_own_price_uses_the_tariffs(): void
    {
        $dataEntry = User::factory()->dataEntry()->create();
        $this->travelTo('2026-09-24 10:00:00');
        $subscription = $this->subscriptionFor($dataEntry, ['initial_reading' => 1200, 'minimum_charge' => 20]);

        $this->actingAs($dataEntry)
            ->post(route('meter-readings.store'), ['subscription_id' => $subscription->id, 'week_start' => '2026-09-18', 'current_reading' => 1250])
            ->assertSessionHasNoErrors();

        $this->assertSame('30.00', MeterReading::sole()->unit_price);
    }

    public function test_a_kilowatt_discount_is_worth_the_subscribers_own_price(): void
    {
        $user = User::factory()->branchAdmin()->create();
        $subscription = $this->subscriptionFor($user, ['kilowatt_price' => 45]);
        $discount = SubscriptionTransaction::recordDiscount($subscription, $user, DiscountMethod::Kilowatt, 2, null);

        $this->assertSame('-90.00', $discount->amount);
    }

    private function userWithKilowattPricePermission(array $attributes = []): User
    {
        $user = User::factory()->dataEntry()->create($attributes);
        $user->permissions()->attach(Permission::firstOrCreate(
            ['key' => PermissionKey::UpdateSubscriptionKilowattPrice->value],
            ['label' => PermissionKey::UpdateSubscriptionKilowattPrice->label()],
        ));

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function subscriptionFor(User $user, array $attributes = []): Subscription
    {
        return Subscription::factory()->create([
            'branch_id' => $user->branch_id,
            'tariff_id' => $this->tariff->id,
            'status' => SubscriptionStatus::Active,
            ...$attributes,
        ]);
    }

    /**
     * The form's request: for a new subscription, or — given one — its own
     * details sent back with the changes.
     *
     * @param  array<string, mixed>|Subscription  $subscriptionOrOverrides
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array|Subscription $subscriptionOrOverrides = [], array $overrides = []): array
    {
        if ($subscriptionOrOverrides instanceof Subscription) {
            $subscription = $subscriptionOrOverrides;

            return [
                'full_name' => $subscription->full_name,
                'national_id' => $subscription->national_id,
                'phone' => $subscription->phone,
                'address' => $subscription->address,
                'tariff_id' => $subscription->tariff_id,
                'status' => $subscription->status->value,
                'minimum_charge' => $subscription->minimum_charge,
                'initial_reading' => $subscription->initial_reading,
                'notes' => $subscription->notes,
                ...$overrides,
            ];
        }

        return [
            'full_name' => 'Kilo Price Customer',
            'national_id' => '012345678',
            'phone' => '0590000000',
            'address' => 'Some street',
            'tariff_id' => $this->tariff->id,
            'status' => SubscriptionStatus::Active->value,
            'minimum_charge' => 10,
            'initial_reading' => 0,
            'notes' => 'Notes',
            ...$subscriptionOrOverrides,
        ];
    }
}
