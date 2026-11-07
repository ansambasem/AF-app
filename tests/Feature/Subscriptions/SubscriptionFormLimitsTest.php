<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\ChargeType;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SubscriptionFormLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $dataEntry;

    private Tariff $tariff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-05 10:00:00');
        $this->branch = Branch::factory()->create();
        $this->dataEntry = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]);
        $this->tariff = Tariff::factory()->residential()->create();
    }

    public function test_a_meter_box_of_another_branch_is_refused_and_one_of_the_users_own_is_accepted(): void
    {
        $own = MeterBox::factory()->create(['branch_id' => $this->branch->id]);
        $foreign = MeterBox::factory()->create(['branch_id' => Branch::factory()->create()->id]);

        $this->actingAs($this->dataEntry)->post(route('subscriptions.store'), $this->payload(['meter_box_id' => $foreign->id]))->assertSessionHasErrors('meter_box_id');
        $this->assertDatabaseCount('subscriptions', 0);

        $this->post(route('subscriptions.store'), $this->payload(['meter_box_id' => $own->id]))->assertSessionHasNoErrors();
        $this->assertSame($own->id, Subscription::sole()->meter_box_id);
    }

    public function test_editing_cannot_move_a_subscription_onto_a_box_of_another_branch(): void
    {
        $foreign = MeterBox::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $this->branch->id]);
        $boxBefore = $subscription->meter_box_id;

        $this->actingAs($this->dataEntry)->put(route('subscriptions.update', $subscription), $this->payload(['meter_box_id' => $foreign->id]))->assertSessionHasErrors('meter_box_id');

        $this->assertSame($boxBefore, $subscription->fresh()->meter_box_id);
    }

    public function test_a_super_admins_box_must_be_one_of_the_branch_they_chose(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $otherBranch = Branch::factory()->create();
        $inOther = MeterBox::factory()->create(['branch_id' => $otherBranch->id]);

        $this->actingAs($superAdmin)->post(route('subscriptions.store'), $this->payload(['branch_id' => $this->branch->id, 'meter_box_id' => $inOther->id]))->assertSessionHasErrors('meter_box_id');
        $this->post(route('subscriptions.store'), $this->payload(['branch_id' => $otherBranch->id, 'meter_box_id' => $inOther->id]))->assertSessionHasNoErrors();
    }

    #[TestWith(['1900-03-01', false])]
    #[TestWith(['1999-12-31', false])]
    #[TestWith(['2000-01-01', true])]
    #[TestWith(['2026-10-05', true])]
    #[TestWith(['2026-10-06', false])]
    #[TestWith(['2090-01-01', false])]
    public function test_the_subscription_date_is_between_2000_and_today(string $date, bool $accepted): void
    {
        $response = $this->actingAs($this->dataEntry)->post(route('subscriptions.store'), $this->payload(['subscription_date' => $date]));

        $accepted ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('subscription_date');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('absurdValues')]
    public function test_absurd_numbers_are_refused(array $overrides, string $errorOn): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())->post(route('subscriptions.store'), $this->payload([...$overrides, 'branch_id' => $this->branch->id]))
            ->assertSessionHasErrors($errorOn);

        $this->assertDatabaseCount('subscriptions', 0);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function absurdValues(): array
    {
        return [
            'a starting reading of nearly ten billion' => [['initial_reading' => '9999999999'], 'initial_reading'],
            'a minimum charge of 100,000' => [['minimum_charge' => 100000], 'minimum_charge'],
            'a fee of a million' => [['charge_subscription_fee' => true, 'subscription_fee' => 1000000], 'subscription_fee'],
            'a fee of 5,000' => [['charge_subscription_fee' => true, 'subscription_fee' => 5000], 'subscription_fee'],
            'a fee of 5,000 not charged yet' => [['subscription_fee' => 5000], 'subscription_fee'],
        ];
    }

    public function test_the_largest_sensible_values_are_accepted(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())->post(route('subscriptions.store'), $this->payload([
            'branch_id' => $this->branch->id,
            'initial_reading' => '9999999.99',
            'minimum_charge' => 10000,
            'charge_subscription_fee' => true,
            'subscription_fee' => 1000,
        ]))->assertSessionHasNoErrors();

        $this->assertSame('1000.00', SubscriptionTransaction::sole()->amount);
    }

    public function test_the_fee_limit_is_the_one_in_the_configuration(): void
    {
        config(['powercollect.limits.subscription_fee' => 200]);

        $this->actingAs($this->dataEntry)->post(route('subscriptions.store'), $this->payload(['charge_subscription_fee' => true, 'subscription_fee' => 201]))->assertSessionHasErrors('subscription_fee');
        $this->post(route('subscriptions.store'), $this->payload(['charge_subscription_fee' => true, 'subscription_fee' => 200]))->assertSessionHasNoErrors();
    }

    public function test_a_subscription_fee_charged_by_hand_has_the_same_limit_but_other_charges_do_not(): void
    {
        $admin = User::factory()->branchAdmin()->create(['branch_id' => $this->branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $this->branch->id]);
        $charge = fn (string $type, string $amount) => $this->actingAs($admin)->post(route('subscriptions.charges.store', $subscription), ['type' => $type, 'amount' => $amount, 'notes' => 'غرامة']);

        $charge(ChargeType::SubscriptionFee->value, '5000')->assertSessionHasErrors('amount');
        $charge(ChargeType::SubscriptionFee->value, '1000')->assertSessionHasNoErrors();
        $charge(ChargeType::Penalty->value, '5000')->assertSessionHasNoErrors();
    }

    #[TestWith(['000000000'])]
    public function test_an_all_zero_identity_number_is_refused_when_registering_and_when_editing_personal_details(string $nationalId): void
    {
        $this->actingAs($this->dataEntry)->post(route('subscriptions.store'), $this->payload(['national_id' => $nationalId]))->assertSessionHasErrors('national_id');

        $subscription = Subscription::factory()->create(['branch_id' => $this->branch->id]);
        $this->patch(route('subscriptions.personal-details.update', $subscription), ['full_name' => 'Name', 'national_id' => $nationalId, 'phone' => '0590000000'])
            ->assertSessionHasErrors('national_id');
        $this->assertNotSame($nationalId, $subscription->fresh()->national_id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'full_name' => 'Registration Customer',
            'national_id' => '123456789',
            'phone' => '0590000000',
            'tariff_id' => $this->tariff->id,
            'status' => SubscriptionStatus::Active->value,
            'minimum_charge' => 10,
            'initial_reading' => 100,
            ...$overrides,
        ];
    }
}
