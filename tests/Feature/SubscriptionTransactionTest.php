<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SubscriptionTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_records_the_subscription_fee_as_a_charge(): void
    {
        $actor = User::factory()->dataEntry()->create();
        $payload = $this->payload();

        $this->actingAs($actor)->post(route('subscriptions.store'), $payload)
            ->assertSessionHasNoErrors()->assertRedirect(route('subscriptions.index'));

        $subscription = Subscription::where('national_id', $payload['national_id'])->sole();
        $this->assertDatabaseHas('subscription_transactions', [
            'subscription_id' => $subscription->id,
            'recorded_by' => $actor->id,
            'type' => 'subscription_fee',
            'amount' => '50.25',
        ]);
        $this->assertDatabaseCount('subscription_transactions', 1);
    }

    #[TestWith([null])]
    #[TestWith(['0'])]
    public function test_registration_without_a_positive_fee_does_not_create_a_charge(?string $fee): void
    {
        $actor = User::factory()->dataEntry()->create();
        $payload = $this->payload();
        $payload['subscription_fee'] = $fee;
        $payload['charge_subscription_fee'] = false;

        $this->actingAs($actor)->post(route('subscriptions.store'), $payload)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    #[TestWith([false])]
    #[TestWith([null])]
    public function test_registration_with_a_fee_does_not_charge_it_when_the_option_is_off_or_omitted(?bool $chargeFee): void
    {
        $actor = User::factory()->dataEntry()->create();
        $payload = $this->payload();
        unset($payload['charge_subscription_fee']);

        if ($chargeFee !== null) {
            $payload['charge_subscription_fee'] = $chargeFee;
        }

        $this->actingAs($actor)->post(route('subscriptions.store'), $payload)
            ->assertSessionHasNoErrors()->assertRedirect(route('subscriptions.index'));

        $subscription = Subscription::where('national_id', $payload['national_id'])->sole();
        $this->assertSame('50.25', $subscription->subscription_fee);
        $this->assertSame(0.0, $subscription->balance());
        $this->assertDatabaseCount('subscription_transactions', 0);
        $this->actingAs($actor)->get(route('subscriptions.statement', $subscription))
            ->assertInertia(fn (Assert $page) => $page->has('entries', 0)->where('summary.balance', '0.00'));
    }

    #[TestWith([null, 'أدخل مبلغ رسوم الاشتراك عند تفعيل تحميل الرسوم.'])]
    #[TestWith(['0', 'يجب أن تكون رسوم الاشتراك أكبر من صفر عند تحميلها.'])]
    public function test_loading_the_fee_on_registration_requires_a_positive_amount(?string $fee, string $message): void
    {
        $actor = User::factory()->dataEntry()->create();
        $payload = $this->payload();
        $payload['subscription_fee'] = $fee;

        $this->actingAs($actor)->post(route('subscriptions.store'), $payload)
            ->assertSessionHasErrors(['subscription_fee' => $message]);

        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_the_load_fee_option_rejects_invalid_values(): void
    {
        $actor = User::factory()->dataEntry()->create();
        $payload = $this->payload();
        $payload['charge_subscription_fee'] = 'invalid';

        $this->actingAs($actor)->post(route('subscriptions.store'), $payload)
            ->assertSessionHasErrors(['charge_subscription_fee' => 'اختر تفعيل تحميل رسوم الاشتراك أو إلغاءه.']);

        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_a_fee_skipped_on_registration_can_be_loaded_later_from_the_statement(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $payload = $this->payload();
        $payload['charge_subscription_fee'] = false;
        $this->actingAs($actor)->post(route('subscriptions.store'), $payload)->assertSessionHasNoErrors();
        $subscription = Subscription::where('national_id', $payload['national_id'])->sole();

        $this->from(route('subscriptions.statement', $subscription))
            ->post(route('subscriptions.charges.store', $subscription), ['type' => 'subscription_fee', 'amount' => '50.25', 'notes' => 'رسوم الاشتراك'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'charge-recorded')
            ->assertRedirect(route('subscriptions.statement', $subscription));

        $this->assertDatabaseHas('subscription_transactions', [
            'subscription_id' => $subscription->id,
            'recorded_by' => $actor->id,
            'type' => 'subscription_fee',
            'amount' => '50.25',
        ]);
        $this->assertDatabaseCount('subscription_transactions', 1);
        $this->get(route('subscriptions.statement', $subscription))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.0.typeLabel', 'رسوم اشتراك')
                ->where('entries.0.isCredit', false)
                ->where('entries.0.recorded.kind', 'charge')
                ->where('subscription.subscriptionFee', '50.25')
                ->where('summary.balance', '50.25')
                ->where('summary.charged', '50.25'));
    }

    public function test_loading_a_subscription_fee_later_requires_balance_adjustment_permission_in_the_same_branch(): void
    {
        $actor = User::factory()->dataEntry()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $actor->branch_id]);
        $payload = ['type' => 'subscription_fee', 'amount' => '50.25'];

        $this->actingAs($actor)->post(route('subscriptions.charges.store', $subscription), $payload)->assertForbidden();
        $this->actingAs(User::factory()->branchAdmin()->create())
            ->post(route('subscriptions.charges.store', $subscription), $payload)->assertForbidden();

        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_invalid_registration_does_not_create_a_charge(): void
    {
        $actor = User::factory()->dataEntry()->create();
        $payload = $this->payload();
        $payload['national_id'] = 'invalid';

        $this->actingAs($actor)->post(route('subscriptions.store'), $payload)->assertSessionHasErrors('national_id');

        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_registration_is_rolled_back_when_the_charge_cannot_be_saved(): void
    {
        $actor = User::factory()->dataEntry()->create();
        $payload = $this->payload();
        $dispatcher = SubscriptionTransaction::getEventDispatcher();
        SubscriptionTransaction::setEventDispatcher(clone $dispatcher);
        SubscriptionTransaction::creating(function (SubscriptionTransaction $transaction): void {
            throw new \RuntimeException('Unable to save the subscription charge.');
        });

        try {
            $this->actingAs($actor)->post(route('subscriptions.store'), $payload)->assertServerError();
        } finally {
            SubscriptionTransaction::setEventDispatcher($dispatcher);
        }

        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_editing_a_subscription_does_not_duplicate_or_rewrite_the_original_charge(): void
    {
        $actor = User::factory()->dataEntry()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'subscription_fee' => '50.25']);
        $transaction = SubscriptionTransaction::factory()->for($subscription)->create(['amount' => '50.25']);
        $payload = $this->payload();
        $payload['national_id'] = $subscription->national_id;

        $this->actingAs($actor)->put(route('subscriptions.update', $subscription), $payload)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('subscription_transactions', 1);
        $this->assertDatabaseHas('subscription_transactions', ['id' => $transaction->id, 'amount' => '50.25']);
    }

    public function test_subscription_history_is_visible_only_within_the_actors_branch(): void
    {
        $actor = User::factory()->dataEntry()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $actor->branch_id]);
        SubscriptionTransaction::factory()->for($subscription)->create(['recorded_by' => $actor->id]);
        SubscriptionTransaction::factory()->create();

        $this->actingAs($actor)->get(route('subscriptions.index'))
            ->assertInertia(fn (Assert $page) => $page->component('Subscriptions/Index')
                ->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.id', $subscription->id)
                ->where('subscriptions.data.0.outstandingBalance', fn ($value): bool => (float) $value === 50.0));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'full_name' => 'Ahmad',
            'national_id' => '012345678',
            'phone' => '0591234567',
            'tariff_id' => Tariff::query()->value('id') ?? Tariff::factory()->residential()->create()->id,
            'status' => 'active',
            'minimum_charge' => 0,
            'initial_reading' => 0,
            'subscription_fee' => '50.25',
            'charge_subscription_fee' => true,
        ];
    }
}
