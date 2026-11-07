<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\ChargeType;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\Subscription;
use App\Models\SubscriptionBulkChange;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconnectionAndFeeLockTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-05 10:00:00');
        $this->admin = User::factory()->branchAdmin()->create(['branch_id' => Branch::factory()->create()->id]);
    }

    /** A subscription who was connected in March 2024 and has been disconnected since. */
    private function disconnectedSince2024(array $attributes = []): Subscription
    {
        $subscription = Subscription::factory()->create([
            'branch_id' => $this->admin->branch_id,
            'status' => SubscriptionStatus::Active,
            'subscription_date' => '2024-03-01',
            'initial_reading' => 100,
            ...$attributes,
        ]);
        $subscription->update(['status' => SubscriptionStatus::Disconnected]);

        return $subscription->fresh();
    }

    /** @return array<string, mixed> */
    private function editPayload(Subscription $subscription, array $overrides = []): array
    {
        return [
            'full_name' => $subscription->full_name,
            'national_id' => $subscription->national_id,
            'phone' => $subscription->phone,
            'tariff_id' => $subscription->tariff_id,
            'status' => $subscription->status->value,
            'minimum_charge' => $subscription->minimum_charge,
            'initial_reading' => $subscription->initial_reading,
            'subscription_fee' => $subscription->subscription_fee,
            'subscription_date' => $subscription->subscription_date?->format('Y-m-d'),
            ...$overrides,
        ];
    }

    public function test_reconnecting_through_the_edit_form_keeps_the_original_subscription_date(): void
    {
        $subscription = $this->disconnectedSince2024();

        $this->actingAs($this->admin)
            ->put(route('subscriptions.update', $subscription), $this->editPayload($subscription, ['status' => 'active']))
            ->assertSessionHasNoErrors();

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame('2024-03-01', $subscription->subscription_date->toDateString());
        $this->assertSame('2026-10-05', $subscription->reconnected_at->toDateString());
    }

    public function test_a_subscription_registered_as_active_starts_today_and_keeps_that_day_when_reconnected(): void
    {
        $this->actingAs($this->admin)->post(route('subscriptions.store'), [
            'full_name' => 'مشترك جديد', 'national_id' => '412345678', 'phone' => '0599123456',
            'tariff_id' => Subscription::factory()->make()->tariff_id, 'status' => 'active', 'initial_reading' => 0, 'minimum_charge' => 0,
        ])->assertSessionHasNoErrors();
        $subscription = Subscription::sole();
        $subscription->update(['status' => SubscriptionStatus::Disconnected]);
        $this->travelTo('2026-10-12 10:00:00');

        $this->actingAs($this->admin)
            ->put(route('subscriptions.update', $subscription), $this->editPayload($subscription->fresh(), ['status' => 'active']))
            ->assertSessionHasNoErrors();

        $subscription->refresh();
        $this->assertSame('2026-10-05', $subscription->subscription_date->toDateString());
        $this->assertSame('2026-10-12', $subscription->reconnected_at->toDateString());
    }

    public function test_a_subscription_registered_after_midnight_business_time_can_be_saved_again_with_its_date(): void
    {
        $this->travelTo('2026-10-05 22:30:00');
        $this->actingAs($this->admin)->post(route('subscriptions.store'), [
            'full_name' => 'مشترك بعد منتصف الليل', 'national_id' => '412345679', 'phone' => '0599123456',
            'tariff_id' => Subscription::factory()->make()->tariff_id, 'status' => 'active', 'initial_reading' => 0, 'minimum_charge' => 0,
        ])->assertSessionHasNoErrors();
        $subscription = Subscription::sole();

        $this->put(route('subscriptions.update', $subscription), $this->editPayload($subscription, ['status' => 'disconnected']))
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-10-06', $subscription->fresh()->subscription_date->toDateString());
        $this->put(route('subscriptions.update', $subscription), $this->editPayload($subscription->fresh(), ['subscription_date' => '2026-10-07']))
            ->assertSessionHasErrors('subscription_date');
    }

    public function test_reconnecting_a_subscription_with_no_recorded_date_keeps_the_day_it_was_first_activated(): void
    {
        $subscription = Subscription::factory()->create([
            'branch_id' => $this->admin->branch_id, 'status' => SubscriptionStatus::Active,
            'subscription_date' => null, 'initial_reading' => 100,
        ]);
        $subscription->update(['status' => SubscriptionStatus::Disconnected]);
        $this->travelTo('2026-10-12 10:00:00');

        $this->actingAs($this->admin)
            ->put(route('subscriptions.update', $subscription), $this->editPayload($subscription->fresh(), ['status' => 'active']))
            ->assertSessionHasNoErrors();

        $subscription->refresh();
        $this->assertSame('2026-10-05', $subscription->subscription_date->toDateString());
        $this->assertSame('2026-10-12', $subscription->reconnected_at->toDateString());
    }

    public function test_a_first_activation_still_starts_the_subscription_today_and_is_not_a_reconnection(): void
    {
        $waiting = Subscription::factory()->create([
            'branch_id' => $this->admin->branch_id,
            'status' => SubscriptionStatus::Suspended,
            'initial_reading' => null,
            'subscription_date' => '2026-01-01',
        ]);

        $this->actingAs($this->admin)
            ->put(route('subscriptions.update', $waiting), $this->editPayload($waiting, ['status' => 'active', 'initial_reading' => 50]))
            ->assertSessionHasNoErrors();

        $waiting->refresh();
        $this->assertSame('2026-10-05', $waiting->subscription_date->toDateString());
        $this->assertNull($waiting->reconnected_at);
    }

    public function test_a_date_picked_while_reconnecting_is_kept_and_the_reconnection_is_still_noted(): void
    {
        $subscription = $this->disconnectedSince2024();

        $this->actingAs($this->admin)
            ->put(route('subscriptions.update', $subscription), $this->editPayload($subscription, ['status' => 'active', 'subscription_date' => '2024-02-10']))
            ->assertSessionHasNoErrors();

        $subscription->refresh();
        $this->assertSame('2024-02-10', $subscription->subscription_date->toDateString());
        $this->assertSame('2026-10-05', $subscription->reconnected_at->toDateString());
    }

    public function test_editing_an_active_subscription_does_not_touch_the_reconnection_date(): void
    {
        $subscription = $this->disconnectedSince2024(['reconnected_at' => '2025-06-01']);
        $subscription->update(['status' => SubscriptionStatus::Active]);

        $this->actingAs($this->admin)
            ->put(route('subscriptions.update', $subscription), $this->editPayload($subscription, ['notes' => 'ملاحظة']))
            ->assertSessionHasNoErrors();

        $this->assertSame('2025-06-01', $subscription->fresh()->reconnected_at->toDateString());
    }

    public function test_reconnecting_in_bulk_keeps_the_original_date_and_undo_puts_everything_back(): void
    {
        $reconnected = $this->disconnectedSince2024();
        $firstTime = Subscription::factory()->create([
            'branch_id' => $this->admin->branch_id,
            'status' => SubscriptionStatus::Suspended,
            'initial_reading' => 0,
            'subscription_date' => '2026-01-01',
        ]);

        $this->actingAs($this->admin)
            ->post(route('subscriptions.bulk-changes.store'), ['field' => 'status', 'value' => 'active', 'ids' => [$reconnected->id, $firstTime->id]])
            ->assertSessionHasNoErrors();

        $reconnected->refresh();
        $firstTime->refresh();
        $this->assertSame('2024-03-01', $reconnected->subscription_date->toDateString());
        $this->assertSame('2026-10-05', $reconnected->reconnected_at->toDateString());
        $this->assertSame('2026-10-05', $firstTime->subscription_date->toDateString());
        $this->assertNull($firstTime->reconnected_at);
        $this->assertNotNull($firstTime->activated_at);

        $this->post(route('subscriptions.bulk-changes.undo', SubscriptionBulkChange::sole()))->assertSessionHasNoErrors();

        $reconnected->refresh();
        $firstTime->refresh();
        $this->assertSame(SubscriptionStatus::Disconnected, $reconnected->status);
        $this->assertNull($reconnected->reconnected_at);
        $this->assertSame('2024-03-01', $reconnected->subscription_date->toDateString());
        $this->assertNotNull($reconnected->activated_at, 'a subscription who really was active before stays one who was');

        // M5: a first activation that is undone leaves a subscription who never was active.
        $this->assertSame(SubscriptionStatus::Suspended, $firstTime->status);
        $this->assertNull($firstTime->activated_at);
        $this->assertSame('2026-01-01', $firstTime->subscription_date->toDateString());
    }

    public function test_undoing_a_bulk_activation_does_not_overwrite_a_date_edited_since(): void
    {
        $subscription = $this->disconnectedSince2024();

        $this->actingAs($this->admin)
            ->post(route('subscriptions.bulk-changes.store'), ['field' => 'status', 'value' => 'active', 'ids' => [$subscription->id]])
            ->assertSessionHasNoErrors();
        $subscription->update(['reconnected_at' => '2026-10-01']);

        $this->post(route('subscriptions.bulk-changes.undo', SubscriptionBulkChange::sole()))->assertSessionHasNoErrors();

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Disconnected, $subscription->status);
        $this->assertSame('2026-10-01', $subscription->reconnected_at->toDateString());
    }

    public function test_a_subscription_fee_that_was_charged_cannot_be_changed_from_the_edit_form(): void
    {
        $subscription = Subscription::factory()->create(['branch_id' => $this->admin->branch_id, 'subscription_fee' => 150]);
        $subscription->transactions()->create([
            'recorded_by' => $this->admin->id,
            'type' => SubscriptionTransaction::TYPE_SUBSCRIPTION_FEE,
            'source_key' => 'subscription-fee:'.$subscription->id,
            'amount' => 150,
            'currency_amount' => 150,
        ]);

        $this->actingAs($this->admin)
            ->put(route('subscriptions.update', $subscription), $this->editPayload($subscription, ['subscription_fee' => 999]))
            ->assertSessionHasErrors(['subscription_fee' => 'رسوم الاشتراك حُمّلت على الحساب ولا يمكن تغيير مبلغها من هنا.']);
        $this->assertSame('150.00', $subscription->fresh()->subscription_fee);

        // Sending the same amount (the form always sends it) is not a change.
        $this->put(route('subscriptions.update', $subscription), $this->editPayload($subscription, ['subscription_fee' => '150.00', 'notes' => 'ok']))
            ->assertSessionHasNoErrors();
        $this->assertSame('ok', $subscription->fresh()->notes);
    }

    public function test_a_subscription_fee_not_yet_charged_can_still_be_changed(): void
    {
        $subscription = Subscription::factory()->create(['branch_id' => $this->admin->branch_id, 'subscription_fee' => 150]);

        $this->actingAs($this->admin)
            ->put(route('subscriptions.update', $subscription), $this->editPayload($subscription, ['subscription_fee' => 200]))
            ->assertSessionHasNoErrors();

        $this->assertSame('200.00', $subscription->fresh()->subscription_fee);
    }

    public function test_a_fee_charged_later_from_the_transactions_locks_the_amount_too(): void
    {
        $subscription = Subscription::factory()->create(['branch_id' => $this->admin->branch_id, 'subscription_fee' => 150]);

        $this->actingAs($this->admin)
            ->post(route('subscriptions.charges.store', $subscription), ['type' => ChargeType::SubscriptionFee->value, 'amount' => 150])
            ->assertSessionHasNoErrors();

        $this->put(route('subscriptions.update', $subscription), $this->editPayload($subscription, ['subscription_fee' => 20]))
            ->assertSessionHasErrors('subscription_fee');
    }
}
