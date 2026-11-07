<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\CircuitBreaker;
use App\Models\Subscription;
use App\Models\SubscriptionBulkChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionBulkChangeTest extends TestCase
{
    use RefreshDatabase;

    private function subscriptionOf(User $user, array $attributes = []): Subscription
    {
        return Subscription::factory()->create(['branch_id' => $user->branch_id, ...$attributes]);
    }

    public function test_a_subscription_without_an_initial_reading_is_not_activated(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $this->travelTo('2026-10-05 10:00:00');
        $ready = $this->subscriptionOf($branchAdmin, ['status' => SubscriptionStatus::Suspended, 'initial_reading' => 0, 'subscription_date' => '2026-01-01']);
        $waiting = $this->subscriptionOf($branchAdmin, ['status' => SubscriptionStatus::Suspended, 'initial_reading' => null]);

        $this->actingAs($branchAdmin)
            ->post(route('subscriptions.bulk-changes.store'), ['field' => 'status', 'value' => 'active', 'ids' => [$ready->id, $waiting->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(SubscriptionStatus::Active, $ready->fresh()->status);
        $this->assertSame('2026-10-05', $ready->fresh()->subscription_date->toDateString());
        $this->assertSame(SubscriptionStatus::Suspended, $waiting->fresh()->status);

        $this->post(route('subscriptions.bulk-changes.store'), ['field' => 'status', 'value' => 'active', 'ids' => [$waiting->id]])
            ->assertSessionHasErrors(['ids' => 'لا يمكن تفعيل مشترك قبل إدخال قراءته السابقة؛ أدخلها من «تعديل المشترك» أولًا.']);
    }

    public function test_an_active_subscription_is_not_put_back_to_waiting(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $active = $this->subscriptionOf($branchAdmin, ['status' => SubscriptionStatus::Active]);

        $this->actingAs($branchAdmin)
            ->post(route('subscriptions.bulk-changes.store'), ['field' => 'status', 'value' => 'suspended', 'ids' => [$active->id]])
            ->assertSessionHasErrors(['ids' => 'لا يمكن إعادة مشترك سبق تفعيله إلى «قيد الانتظار»؛ غيّر حالته إلى «مفصول».']);

        $this->assertSame(SubscriptionStatus::Active, $active->fresh()->status);
    }

    public function test_a_disconnected_subscription_who_was_active_is_not_put_back_to_waiting_in_bulk_but_one_never_active_is(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $wasActive = $this->subscriptionOf($branchAdmin, ['status' => SubscriptionStatus::Active]);
        $wasActive->update(['status' => SubscriptionStatus::Disconnected]);
        $neverActive = $this->subscriptionOf($branchAdmin, ['status' => SubscriptionStatus::Disconnected]);

        $this->actingAs($branchAdmin)
            ->post(route('subscriptions.bulk-changes.store'), ['field' => 'status', 'value' => 'suspended', 'ids' => [$wasActive->id, $neverActive->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(SubscriptionStatus::Disconnected, $wasActive->fresh()->status);
        $this->assertSame(SubscriptionStatus::Suspended, $neverActive->fresh()->status);
    }

    public function test_the_minimum_charge_is_set_for_the_ticked_subscriptions_and_kept_for_undo(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $first = $this->subscriptionOf($branchAdmin, ['minimum_charge' => 10]);
        $second = $this->subscriptionOf($branchAdmin, ['minimum_charge' => 15]);
        $alreadySet = $this->subscriptionOf($branchAdmin, ['minimum_charge' => 25]);
        $notTicked = $this->subscriptionOf($branchAdmin, ['minimum_charge' => 10]);

        $this->actingAs($branchAdmin)
            ->post(route('subscriptions.bulk-changes.store'), [
                'field' => 'minimum_charge',
                'mode' => 'amount',
                'value' => '25',
                'ids' => [$first->id, $second->id, $alreadySet->id],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'subscriptions-bulk-changed');

        $this->assertEquals(25, (float) $first->fresh()->minimum_charge);
        $this->assertEquals(25, (float) $second->fresh()->minimum_charge);
        $this->assertEquals(10, (float) $notTicked->fresh()->minimum_charge);
        $change = SubscriptionBulkChange::sole();
        $this->assertSame(2, $change->changed_count);
        $this->assertSame($branchAdmin->branch_id, $change->branch_id);
        $this->assertSame('الحد الأدنى ← 25 شيكل', $change->description);
        $this->assertDatabaseHas('subscription_bulk_change_items', ['subscription_id' => $second->id, 'old_value' => '15.00', 'new_value' => '25.00']);
        $this->assertDatabaseMissing('subscription_bulk_change_items', ['subscription_id' => $alreadySet->id]);
    }

    public function test_every_subscription_matching_the_lists_filters_gets_the_new_status_but_never_another_branchs(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $active = $this->subscriptionOf($branchAdmin, ['status' => SubscriptionStatus::Active]);
        $suspended = $this->subscriptionOf($branchAdmin, ['status' => SubscriptionStatus::Suspended]);
        $otherBranch = Subscription::factory()->create(['status' => SubscriptionStatus::Active]);

        $this->actingAs($branchAdmin)
            ->post(route('subscriptions.bulk-changes.store'), [
                'field' => 'status',
                'value' => SubscriptionStatus::Disconnected->value,
                'all' => true,
                'filter' => ['status' => SubscriptionStatus::Active->value],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(SubscriptionStatus::Disconnected, $active->fresh()->status);
        $this->assertSame(SubscriptionStatus::Suspended, $suspended->fresh()->status);
        $this->assertSame(SubscriptionStatus::Active, $otherBranch->fresh()->status);
    }

    public function test_by_circuit_breaker_each_subscription_gets_their_breakers_minimum(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $withBreaker = $this->subscriptionOf($branchAdmin, ['minimum_charge' => 5, 'circuit_breaker_id' => CircuitBreaker::factory()->create(['minimum_payment' => 40])->id]);
        $withoutBreaker = $this->subscriptionOf($branchAdmin, ['minimum_charge' => 5, 'circuit_breaker_id' => null]);

        $this->actingAs($branchAdmin)
            ->post(route('subscriptions.bulk-changes.store'), ['field' => 'minimum_charge', 'mode' => 'circuit_breaker', 'ids' => [$withBreaker->id, $withoutBreaker->id]])
            ->assertSessionHasNoErrors();

        $this->assertEquals(40, (float) $withBreaker->fresh()->minimum_charge);
        $this->assertEquals(5, (float) $withoutBreaker->fresh()->minimum_charge);
    }

    public function test_the_minimum_charge_takes_its_own_permission_while_the_status_does_not(): void
    {
        $dataEntry = User::factory()->dataEntry()->create();
        $subscription = $this->subscriptionOf($dataEntry, ['minimum_charge' => 10]);
        $collector = User::factory()->collector()->create(['branch_id' => $dataEntry->branch_id]);

        $this->actingAs($dataEntry)
            ->post(route('subscriptions.bulk-changes.store'), ['field' => 'minimum_charge', 'mode' => 'amount', 'value' => 50, 'ids' => [$subscription->id]])
            ->assertForbidden();
        $this->actingAs($collector)
            ->post(route('subscriptions.bulk-changes.store'), ['field' => 'status', 'value' => 'suspended', 'ids' => [$subscription->id]])
            ->assertForbidden();
        $this->actingAs($dataEntry)
            ->post(route('subscriptions.bulk-changes.store'), ['field' => 'status', 'value' => 'disconnected', 'ids' => [$subscription->id]])
            ->assertSessionHasNoErrors();

        $this->assertEquals(10, (float) $subscription->fresh()->minimum_charge);
        $this->assertSame(SubscriptionStatus::Disconnected, $subscription->fresh()->status);
    }

    public function test_ticked_subscriptions_of_another_branch_are_left_out(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $foreign = Subscription::factory()->create(['minimum_charge' => 10]);

        $this->actingAs($branchAdmin)
            ->post(route('subscriptions.bulk-changes.store'), ['field' => 'minimum_charge', 'mode' => 'amount', 'value' => 99, 'ids' => [$foreign->id]])
            ->assertSessionHasErrors(['ids' => 'لا يوجد بين المختارين من تتغير قيمته.']);

        $this->assertEquals(10, (float) $foreign->fresh()->minimum_charge);
        $this->assertDatabaseCount('subscription_bulk_changes', 0);
    }

    public function test_a_bulk_change_needs_its_value_and_subscriptions(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();

        $this->actingAs($branchAdmin)
            ->post(route('subscriptions.bulk-changes.store'), ['field' => 'minimum_charge', 'mode' => 'amount'])
            ->assertSessionHasErrors(['value' => 'أدخل القيمة الجديدة.', 'ids' => 'اختر مشتركًا واحدًا على الأقل.']);
        $this->actingAs($branchAdmin)
            ->post(route('subscriptions.bulk-changes.store'), ['field' => 'status', 'value' => 'archived', 'ids' => [1]])
            ->assertSessionHasErrors('value');
    }

    public function test_undo_puts_back_the_old_values_except_where_changed_since(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $first = $this->subscriptionOf($branchAdmin, ['minimum_charge' => 10]);
        $changedSince = $this->subscriptionOf($branchAdmin, ['minimum_charge' => 12]);
        $this->actingAs($branchAdmin)->post(route('subscriptions.bulk-changes.store'), [
            'field' => 'minimum_charge', 'mode' => 'amount', 'value' => 30, 'ids' => [$first->id, $changedSince->id],
        ]);
        $changedSince->update(['minimum_charge' => 45]);
        $change = SubscriptionBulkChange::sole();

        $this->actingAs($branchAdmin)->post(route('subscriptions.bulk-changes.undo', $change))->assertSessionHas('status', 'subscriptions-bulk-undone');

        $this->assertEquals(10, (float) $first->fresh()->minimum_charge);
        $this->assertEquals(45, (float) $changedSince->fresh()->minimum_charge);
        $change->refresh();
        $this->assertSame(1, $change->restored_count);
        $this->assertSame($branchAdmin->id, $change->undone_by);

        $this->actingAs($branchAdmin)
            ->post(route('subscriptions.bulk-changes.undo', $change))
            ->assertSessionHasErrors(['undo' => 'تم التراجع عن هذا التعديل من قبل.']);
    }

    public function test_another_branchs_bulk_change_can_be_neither_seen_nor_undone(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $foreignAdmin = User::factory()->branchAdmin()->create();
        $subscription = $this->subscriptionOf($foreignAdmin, ['minimum_charge' => 10]);
        $this->actingAs($foreignAdmin)->post(route('subscriptions.bulk-changes.store'), [
            'field' => 'minimum_charge', 'mode' => 'amount', 'value' => 30, 'ids' => [$subscription->id],
        ]);
        $change = SubscriptionBulkChange::sole();

        $this->actingAs($branchAdmin)->get(route('subscriptions.bulk-changes.index'))
            ->assertInertia(fn ($page) => $page->component('Subscriptions/BulkChanges')->has('changes.data', 0));
        $this->actingAs($branchAdmin)->post(route('subscriptions.bulk-changes.undo', $change))->assertNotFound();

        $this->assertEquals(30, (float) $subscription->fresh()->minimum_charge);
    }

    public function test_the_log_lists_a_change_and_shows_its_subscriptions_before_after_and_now(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $subscription = $this->subscriptionOf($branchAdmin, ['status' => SubscriptionStatus::Active]);
        $this->actingAs($branchAdmin)->post(route('subscriptions.bulk-changes.store'), ['field' => 'status', 'value' => 'disconnected', 'ids' => [$subscription->id]]);
        $change = SubscriptionBulkChange::sole();

        $this->actingAs($branchAdmin)->get(route('subscriptions.bulk-changes.index', ['change' => $change->id]))
            ->assertInertia(fn ($page) => $page
                ->where('changes.data.0.description', 'الحالة ← مفصول')
                ->where('changes.data.0.canUndo', true)
                ->reloadOnly('details', fn ($reload) => $reload
                    ->where('details.items.0.name', $subscription->displayName())
                    ->where('details.items.0.old', 'نشط')
                    ->where('details.items.0.new', 'مفصول')
                    ->where('details.items.0.now', 'مفصول')));
    }

    public function test_the_list_can_be_narrowed_to_chosen_subscriptions_for_printing(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $chosen = $this->subscriptionOf($branchAdmin);
        $this->subscriptionOf($branchAdmin);

        $this->actingAs($branchAdmin)->get(route('subscriptions.index', ['filter' => ['ids' => (string) $chosen->id]]))
            ->assertInertia(fn ($page) => $page->has('subscriptions.data', 1)->where('subscriptions.data.0.id', $chosen->id));
    }
}
