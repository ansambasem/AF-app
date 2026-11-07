<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\PermissionKey;
use App\Enums\SubscriptionStatus;
use App\Models\CircuitBreaker;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\Tariff;
use App\Models\TariffSegment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SubscriptionValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_subscribers_are_registered_without_a_national_id_or_mobile_number(): void
    {
        $payload = $this->validPayload();

        $this->post(route('subscriptions.store'), [...$payload, 'national_id' => '', 'phone' => ''])->assertSessionHasNoErrors();
        $this->post(route('subscriptions.store'), [...$payload, 'full_name' => 'Second Old Customer', 'national_id' => null, 'phone' => null])->assertSessionHasNoErrors();

        $this->assertSame([[null, null], [null, null]], Subscription::query()->orderBy('id')->get()->map(fn (Subscription $subscription): array => [$subscription->national_id, $subscription->contactPhone()])->all());
        $this->assertDatabaseCount('subscriber_profiles', 2);
    }

    public function test_a_mobile_number_given_must_still_be_a_valid_one(): void
    {
        $payload = $this->validPayload();

        $this->post(route('subscriptions.store'), [...$payload, 'phone' => '0512345678'])
            ->assertSessionHasErrors('phone');

        $this->assertDatabaseCount('subscriptions', 0);
    }

    #[TestWith(['12345678'])]
    #[TestWith(['1234567890'])]
    #[TestWith(['12345678A'])]
    public function test_national_id_must_be_exactly_nine_digits(string $nationalId): void
    {
        $payload = $this->validPayload();
        $payload['national_id'] = $nationalId;

        $this->post(route('subscriptions.store'), $payload)
            ->assertSessionHasErrors('national_id');

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_national_id_must_be_unique_across_subscriptions(): void
    {
        $payload = $this->validPayload();
        $existing = Subscription::factory()->create(['national_id' => $payload['national_id']]);

        $this->assertNotEquals(auth()->user()->branch_id, $existing->branch_id);

        $this->post(route('subscriptions.store'), $payload)
            ->assertSessionHasErrors('national_id');

        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_national_id_uniqueness_ignores_the_current_subscription_on_update(): void
    {
        $payload = $this->validPayload();
        $subscription = Subscription::factory()->create([
            'branch_id' => auth()->user()->branch_id,
            'national_id' => $payload['national_id'],
        ]);

        $this->put(route('subscriptions.update', $subscription), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('subscriptions.index'));

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'national_id' => '012345678',
            'initial_reading' => 0,
        ]);
    }

    public function test_editing_a_subscriber_with_no_mobile_number_keeps_it_empty_until_one_is_given(): void
    {
        $payload = $this->validPayload();
        $subscription = Subscription::factory()->create([
            'branch_id' => auth()->user()->branch_id,
            'national_id' => $payload['national_id'],
            'phone' => null,
            'subscription_phone' => null,
        ]);

        $this->put(route('subscriptions.update', $subscription), [...$payload, 'phone' => '', 'subscription_phone' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull($subscription->fresh()->contactPhone());
        $this->put(route('subscriptions.update', $subscription), [...$payload, 'phone' => '', 'subscription_phone' => '0512345678'])
            ->assertSessionHasErrors('subscription_phone');

        $this->put(route('subscriptions.update', $subscription), [...$payload, 'phone' => '', 'subscription_phone' => '0599123456'])
            ->assertSessionHasNoErrors();
        $this->assertSame('0599123456', $subscription->fresh()->contactPhone());
    }

    public function test_an_active_subscription_needs_their_initial_reading(): void
    {
        $payload = $this->validPayload();
        unset($payload['initial_reading']);

        $this->post(route('subscriptions.store'), $payload)
            ->assertSessionHasErrors(['initial_reading' => 'أدخل القراءة السابقة قبل تفعيل المشترك؛ منها يبدأ حساب استهلاكه.']);

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_a_subscription_not_yet_active_can_be_registered_before_their_reading_is_known(): void
    {
        $payload = [...$this->validPayload(), 'status' => SubscriptionStatus::Suspended->value, 'initial_reading' => ''];

        $this->post(route('subscriptions.store'), $payload)->assertSessionHasNoErrors();

        $this->assertNull(Subscription::sole()->initial_reading);
    }

    #[TestWith([-1])]
    #[TestWith([1.555])]
    #[TestWith(['invalid'])]
    public function test_initial_reading_must_be_non_negative_with_at_most_two_decimal_places(int|float|string $reading): void
    {
        $payload = $this->validPayload();
        $payload['initial_reading'] = $reading;

        $this->post(route('subscriptions.store'), $payload)
            ->assertSessionHasErrors('initial_reading');

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_registration_preserves_leading_zeroes_and_accepts_zero_initial_reading(): void
    {
        $payload = $this->validPayload();

        $this->post(route('subscriptions.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('subscriptions.index'));

        $this->assertDatabaseHas('subscriptions', [
            'national_id' => '012345678',
            'initial_reading' => 0,
            'branch_id' => auth()->user()->branch_id,
        ]);
    }

    public function test_registration_accepts_a_decimal_initial_reading(): void
    {
        $payload = $this->validPayload();
        $payload['initial_reading'] = '255.2';

        $this->post(route('subscriptions.store'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(255.2, Subscription::sole()->initial_reading);
    }

    public function test_activating_a_waiting_subscription_starts_their_subscription_today_from_the_new_reading(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $payload = $this->validPayload();
        $subscription = Subscription::factory()->create([
            'branch_id' => auth()->user()->branch_id,
            'status' => SubscriptionStatus::Suspended,
            'subscription_date' => '2026-01-01',
        ]);

        $this->put(route('subscriptions.update', $subscription), [...$payload, 'subscription_date' => '2026-01-01', 'initial_reading' => 140.5])
            ->assertSessionHasNoErrors();

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame('2026-10-05', $subscription->subscription_date->toDateString());
        $this->assertSame(140.5, $subscription->initial_reading);
    }

    public function test_activating_needs_the_starting_reading_and_keeps_a_date_picked_by_hand(): void
    {
        $payload = $this->validPayload();
        $subscription = Subscription::factory()->create(['branch_id' => auth()->user()->branch_id, 'status' => SubscriptionStatus::Suspended]);

        $this->put(route('subscriptions.update', $subscription), [...$payload, 'initial_reading' => ''])
            ->assertSessionHasErrors('initial_reading');

        $this->put(route('subscriptions.update', $subscription), [...$payload, 'subscription_date' => '2026-09-20'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-09-20', $subscription->fresh()->subscription_date->toDateString());
    }

    public function test_saving_an_already_active_subscription_leaves_their_subscription_date_alone(): void
    {
        $payload = $this->validPayload();
        $subscription = Subscription::factory()->create([
            'branch_id' => auth()->user()->branch_id,
            'status' => SubscriptionStatus::Active,
            'subscription_date' => '2026-01-01',
        ]);

        $this->put(route('subscriptions.update', $subscription), [...$payload, 'subscription_date' => '2026-01-01'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-01-01', $subscription->fresh()->subscription_date->toDateString());
    }

    public function test_a_subscription_added_without_the_fee_can_be_charged_it_when_edited(): void
    {
        $payload = $this->validPayload();
        $subscription = Subscription::factory()->create(['branch_id' => auth()->user()->branch_id]);

        $this->put(route('subscriptions.update', $subscription), [...$payload, 'charge_subscription_fee' => true, 'subscription_fee' => ''])
            ->assertSessionHasErrors('subscription_fee');
        $this->assertSame(0, $subscription->transactions()->count());

        $this->put(route('subscriptions.update', $subscription), [...$payload, 'charge_subscription_fee' => true, 'subscription_fee' => '45'])
            ->assertSessionHasNoErrors();

        $fee = $subscription->transactions()->sole();
        $this->assertSame('subscription_fee', $fee->type);
        $this->assertSame('45.00', $fee->amount);
        $this->assertTrue($subscription->fresh()->hasSubscriptionFeeCharge());
    }

    public function test_the_fee_is_charged_only_once(): void
    {
        $payload = $this->validPayload();
        $subscription = Subscription::factory()->create(['branch_id' => auth()->user()->branch_id]);
        $charge = [...$payload, 'charge_subscription_fee' => true, 'subscription_fee' => '45'];

        $this->put(route('subscriptions.update', $subscription), $charge)->assertSessionHasNoErrors();
        $this->put(route('subscriptions.update', $subscription), $charge)->assertSessionHasNoErrors();

        $this->assertSame(1, $subscription->transactions()->count());
    }

    public function test_an_active_subscription_can_be_disconnected_but_not_put_back_to_waiting(): void
    {
        $payload = $this->validPayload();
        $subscription = Subscription::factory()->create(['branch_id' => auth()->user()->branch_id, 'status' => SubscriptionStatus::Active]);

        $this->put(route('subscriptions.update', $subscription), [...$payload, 'status' => SubscriptionStatus::Suspended->value])
            ->assertSessionHasErrors(['status' => 'لا يمكن إعادة مشترك سبق تفعيله إلى «قيد الانتظار»؛ غيّر حالته إلى «مفصول».']);
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);

        $this->put(route('subscriptions.update', $subscription), [...$payload, 'status' => SubscriptionStatus::Disconnected->value])
            ->assertSessionHasNoErrors();
        $this->assertSame(SubscriptionStatus::Disconnected, $subscription->fresh()->status);
    }

    public function test_a_disconnected_subscription_who_was_never_active_can_wait_but_one_who_was_active_cannot(): void
    {
        $payload = $this->validPayload();
        $branch = ['branch_id' => auth()->user()->branch_id];
        $neverActive = Subscription::factory()->create([...$branch, 'status' => SubscriptionStatus::Disconnected]);
        $wasActive = Subscription::factory()->create([...$branch, 'status' => SubscriptionStatus::Active, 'national_id' => '987654321']);
        $wasActive->update(['status' => SubscriptionStatus::Disconnected]);
        $waiting = [...$payload, 'status' => SubscriptionStatus::Suspended->value];

        $this->put(route('subscriptions.update', $neverActive), $waiting)->assertSessionHasNoErrors();
        $this->assertSame(SubscriptionStatus::Suspended, $neverActive->fresh()->status);

        $this->put(route('subscriptions.update', $wasActive), [...$waiting, 'national_id' => '987654321'])->assertSessionHasErrors('status');
        $this->assertSame(SubscriptionStatus::Disconnected, $wasActive->fresh()->status);
    }

    public function test_update_rejects_another_subscriptions_national_id(): void
    {
        $payload = $this->validPayload();
        Subscription::factory()->create(['national_id' => $payload['national_id']]);
        $subscription = Subscription::factory()->create([
            'branch_id' => auth()->user()->branch_id,
            'national_id' => '987654321',
        ]);

        $this->put(route('subscriptions.update', $subscription), $payload)
            ->assertSessionHasErrors('national_id');

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'national_id' => '987654321',
        ]);
    }

    public function test_update_requires_the_initial_reading_but_not_the_national_id(): void
    {
        $payload = $this->validPayload();
        $subscription = Subscription::factory()->create(['branch_id' => auth()->user()->branch_id]);
        unset($payload['national_id'], $payload['initial_reading']);

        $this->put(route('subscriptions.update', $subscription), $payload)
            ->assertSessionHasErrors('initial_reading')
            ->assertSessionDoesntHaveErrors('national_id');

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'national_id' => $subscription->national_id,
            'initial_reading' => $subscription->initial_reading,
        ]);
    }

    private function validPayload(): array
    {
        $this->actingAs(User::factory()->dataEntry()->create());

        return [
            'full_name' => 'Registration Customer',
            'national_id' => '012345678',
            'phone' => '0590000000',
            'address' => 'Some street',
            'tariff_id' => Tariff::factory()->residential()->create()->id,
            'status' => SubscriptionStatus::Active->value,
            'minimum_charge' => 10,
            'initial_reading' => 0,
            'notes' => 'Registration notes',
        ];
    }

    public function test_registering_a_subscription_with_a_circuit_breaker_stores_it(): void
    {
        $payload = $this->validPayload();
        $circuitBreaker = CircuitBreaker::factory()->create(['ampere' => 4, 'minimum_payment' => 20]);
        $payload['circuit_breaker_id'] = $circuitBreaker->id;

        $this->post(route('subscriptions.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('subscriptions.index'));

        $this->assertDatabaseHas('subscriptions', [
            'national_id' => $payload['national_id'],
            'circuit_breaker_id' => $circuitBreaker->id,
        ]);
    }

    public function test_registering_a_subscription_with_a_circuit_breaker_snapshots_its_minimum_payment(): void
    {
        $circuitBreaker = CircuitBreaker::factory()->create(['ampere' => 4, 'minimum_payment' => 20]);
        $payload = $this->validPayload();
        $payload['circuit_breaker_id'] = $circuitBreaker->id;
        $payload['minimum_charge'] = 20;
        auth()->user()->permissions()->attach(Permission::idsFor([PermissionKey::UpdateCircuitBreakers]));

        $this->post(route('subscriptions.store'), $payload)
            ->assertSessionHasNoErrors();

        $this->put(route('circuit-breakers.update', $circuitBreaker), [
            'ampere' => 4,
            'minimum_payment' => 35,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('circuit_breakers', [
            'id' => $circuitBreaker->id,
            'minimum_payment' => 35,
        ]);

        $this->assertDatabaseHas('subscriptions', [
            'national_id' => $payload['national_id'],
            'circuit_breaker_id' => $circuitBreaker->id,
            'minimum_charge' => 20,
        ]);
    }

    public function test_registering_a_subscription_with_a_customer_segment_stores_it(): void
    {
        $payload = $this->validPayload();
        $segment = TariffSegment::factory()->create(['name' => 'مساجد']);
        $payload['tariff_segment_id'] = $segment->id;

        $this->post(route('subscriptions.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('subscriptions.index'));

        $this->assertDatabaseHas('subscriptions', [
            'national_id' => $payload['national_id'],
            'tariff_segment_id' => $segment->id,
        ]);
    }

    public function test_a_customer_segment_can_be_given_whatever_the_subscriptions_tariff(): void
    {
        $payload = $this->validPayload();
        $segment = TariffSegment::factory()->create(['name' => 'مدارس']);
        $payload['tariff_id'] = Tariff::factory()->commercial()->create()->id;
        $payload['tariff_segment_id'] = $segment->id;

        $this->post(route('subscriptions.store'), $payload)->assertSessionHasNoErrors();

        $this->assertSame($segment->id, Subscription::sole()->tariff_segment_id);
    }

    public function test_a_customer_segment_that_does_not_exist_is_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['tariff_segment_id'] = 999999;

        $this->post(route('subscriptions.store'), $payload)->assertSessionHasErrors('tariff_segment_id');
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_subscription_circuit_breaker_must_exist(): void
    {
        $payload = $this->validPayload();
        $payload['circuit_breaker_id'] = 999999;

        $this->post(route('subscriptions.store'), $payload)->assertSessionHasErrors('circuit_breaker_id');
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_address_and_notes_can_be_omitted(): void
    {
        $payload = $this->validPayload();
        unset($payload['address'], $payload['notes']);

        $this->post(route('subscriptions.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('subscriptions.index'));

        $this->assertDatabaseHas('subscriptions', [
            'national_id' => $payload['national_id'],
            'address' => null,
            'notes' => null,
        ]);
    }

    #[TestWith(['0591234567'])]
    #[TestWith(['0561234567'])]
    public function test_registration_accepts_supported_phone_prefixes_and_blank_optional_fields(string $phone): void
    {
        $payload = $this->validPayload();
        $payload['phone'] = $phone;
        $payload['address'] = '';
        $payload['notes'] = '';

        $this->post(route('subscriptions.store'), $payload)->assertSessionHasNoErrors()
            ->assertRedirect(route('subscriptions.index'));

        $this->assertDatabaseHas('subscriptions', [
            'national_id' => $payload['national_id'],
            'phone' => $phone,
            'address' => null,
            'notes' => null,
        ]);
    }

    #[TestWith(['059123456'])]
    #[TestWith(['05912345678'])]
    #[TestWith(['0571234567'])]
    #[TestWith(['059123456A'])]
    public function test_registration_rejects_invalid_phone_numbers(string $phone): void
    {
        $payload = $this->validPayload();
        $payload['phone'] = $phone;

        $this->post(route('subscriptions.store'), $payload)->assertSessionHasErrors('phone');

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_update_rejects_invalid_phone_without_changing_the_subscription(): void
    {
        $payload = $this->validPayload();
        $subscription = Subscription::factory()->create([
            'branch_id' => auth()->user()->branch_id,
            'phone' => '0591234567',
        ]);
        $payload['phone'] = '0571234567';

        $this->put(route('subscriptions.update', $subscription), $payload)->assertSessionHasErrors('phone');

        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id, 'phone' => '0591234567']);
    }

    public function test_update_accepts_valid_phone_and_clears_optional_fields(): void
    {
        $payload = $this->validPayload();
        $subscription = Subscription::factory()->create(['branch_id' => auth()->user()->branch_id]);
        $payload['phone'] = '0561234567';
        $payload['address'] = '';
        $payload['notes'] = '';

        $this->put(route('subscriptions.update', $subscription), $payload)->assertSessionHasNoErrors()
            ->assertRedirect(route('subscriptions.index'));

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'phone' => '0561234567',
            'address' => null,
            'notes' => null,
        ]);
    }

    public function test_minimum_charge_is_required(): void
    {
        $payload = $this->validPayload();
        unset($payload['minimum_charge']);

        $this->post(route('subscriptions.store'), $payload)
            ->assertSessionHasErrors('minimum_charge');

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_a_user_with_the_permission_can_override_minimum_charge_independent_of_the_circuit_breaker(): void
    {
        $payload = $this->validPayload();
        auth()->user()->permissions()->attach(
            Permission::create([
                'key' => PermissionKey::UpdateSubscriptionMinimumCharge->value,
                'label' => PermissionKey::UpdateSubscriptionMinimumCharge->label(),
            ]),
        );
        $circuitBreaker = CircuitBreaker::factory()->create(['ampere' => 4, 'minimum_payment' => 20]);
        $payload['circuit_breaker_id'] = $circuitBreaker->id;
        $payload['minimum_charge'] = 35;

        $this->post(route('subscriptions.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('subscriptions.index'));

        $this->assertDatabaseHas('subscriptions', [
            'national_id' => $payload['national_id'],
            'circuit_breaker_id' => $circuitBreaker->id,
            'minimum_charge' => 35,
        ]);
    }

    public function test_a_branch_admin_can_override_minimum_charge_without_a_grant(): void
    {
        $payload = $this->validPayload();
        $this->actingAs(User::factory()->branchAdmin()->create());
        $circuitBreaker = CircuitBreaker::factory()->create(['ampere' => 4, 'minimum_payment' => 20]);
        $payload['circuit_breaker_id'] = $circuitBreaker->id;
        $payload['minimum_charge'] = 35;

        $this->post(route('subscriptions.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('subscriptions.index'));

        $this->assertDatabaseHas('subscriptions', [
            'national_id' => $payload['national_id'],
            'circuit_breaker_id' => $circuitBreaker->id,
            'minimum_charge' => 35,
        ]);
    }

    public function test_a_user_without_the_permission_cannot_override_minimum_charge(): void
    {
        $payload = $this->validPayload();
        $circuitBreaker = CircuitBreaker::factory()->create(['ampere' => 4, 'minimum_payment' => 20]);
        $payload['circuit_breaker_id'] = $circuitBreaker->id;
        $payload['minimum_charge'] = 999;

        $this->post(route('subscriptions.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('subscriptions.index'));

        $this->assertDatabaseHas('subscriptions', [
            'national_id' => $payload['national_id'],
            'circuit_breaker_id' => $circuitBreaker->id,
            'minimum_charge' => 20,
        ]);
    }

    public function test_a_user_without_the_permission_keeps_the_existing_minimum_charge_on_update_with_no_circuit_breaker(): void
    {
        $dataEntry = User::factory()->dataEntry()->create();
        $this->actingAs($dataEntry);
        $subscription = Subscription::factory()->create([
            'branch_id' => $dataEntry->branch_id,
            'circuit_breaker_id' => null,
            'minimum_charge' => 42,
        ]);

        $payload = [
            'full_name' => $subscription->full_name,
            'national_id' => $subscription->national_id,
            'phone' => $subscription->phone,
            'address' => $subscription->address,
            'tariff_id' => $subscription->tariff_id,
            'status' => $subscription->status->value,
            'minimum_charge' => 999,
            'initial_reading' => $subscription->initial_reading,
            'notes' => $subscription->notes,
        ];

        $this->put(route('subscriptions.update', $subscription), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('subscriptions.index'));

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'minimum_charge' => 42,
        ]);
    }
}
