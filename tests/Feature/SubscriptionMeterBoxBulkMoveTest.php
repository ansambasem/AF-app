<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\MeterBox;
use App\Models\MeterReading;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionBulkChange;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubscriptionMeterBoxBulkMoveTest extends TestCase
{
    use RefreshDatabase;

    public function test_moves_only_selected_subscriptions_and_preserves_their_financial_and_reading_history(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $old = MeterBox::factory()->create(['branch_id' => $actor->branch_id]);
        $target = MeterBox::factory()->create(['branch_id' => $actor->branch_id, 'name' => 'الشافي', 'name_suffix' => '2', 'box_number' => '110303']);
        $selected = Subscription::factory()->count(3)->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $old->id]);
        $unselected = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $old->id]);
        $payment = SubscriptionTransaction::factory()->for($selected[0])->create(['amount' => '-125.00']);
        $reading = MeterReading::factory()->for($selected[0])->create();
        $originalPayment = $payment->refresh()->getRawOriginal();
        $originalReading = $reading->refresh()->getRawOriginal();
        $originalSubscription = $selected[0]->refresh()->getRawOriginal();

        $this->actingAs($actor)->post(route('subscriptions.bulk-changes.store'), [
            'field' => 'meter_box_id', 'value' => $target->id, 'ids' => $selected->modelKeys(), 'expected_count' => 3,
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'subscriptions-bulk-changed');

        foreach ($selected as $subscription) {
            $this->assertSame($target->id, $subscription->fresh()->meter_box_id);
        }
        $this->assertSame($old->id, $unselected->fresh()->meter_box_id);
        $this->assertSame($originalPayment, $payment->fresh()->getRawOriginal());
        $this->assertSame($originalReading, $reading->fresh()->getRawOriginal());
        $this->assertSame(
            collect($originalSubscription)->except(['meter_box_id', 'updated_at'])->all(),
            collect($selected[0]->fresh()->getRawOriginal())->except(['meter_box_id', 'updated_at'])->all(),
        );
        $change = SubscriptionBulkChange::sole();
        $this->assertSame($actor->id, $change->user_id);
        $this->assertSame(3, $change->changed_count);
        $this->assertDatabaseHas('subscription_bulk_change_items', ['subscription_id' => $selected[0]->id, 'old_value' => (string) $old->id, 'new_value' => (string) $target->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $actor->id]);

        $this->get(route('subscriptions.bulk-changes.index', ['change' => $change->id]))->assertInertia(fn ($page) => $page
            ->where('changes.data.0.description', 'نقل إلى الطبلون ← الشافي 2 - (110303)')
            ->where('changes.data.0.canUndo', true)
            ->reloadOnly('details', fn ($reload) => $reload
                ->where('details.items.0.old', $old->label())
                ->where('details.items.0.new', $target->label())
                ->where('details.items.0.now', $target->label())));
    }

    public function test_filter_selection_moves_all_matching_subscriptions_and_skips_those_already_in_the_target(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $target = MeterBox::factory()->create(['branch_id' => $actor->branch_id]);
        $selected = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => null]);
        $alreadyThere = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $target->id]);
        $excluded = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => null]);
        SubscriptionTransaction::factory()->for($selected)->create(['amount' => 30]);
        SubscriptionTransaction::factory()->for($alreadyThere)->create(['amount' => 40]);
        SubscriptionTransaction::factory()->for($excluded)->create(['amount' => 10]);

        $this->actingAs($actor)->post(route('subscriptions.bulk-changes.store'), [
            'field' => 'meter_box_id', 'value' => $target->id, 'all' => true, 'expected_count' => 2,
            'filter' => ['balance_side' => 'debit', 'balance_operator' => 'gt', 'balance_amount' => 20],
        ])->assertSessionHasNoErrors();

        $this->assertSame($target->id, $selected->fresh()->meter_box_id);
        $this->assertNull($excluded->fresh()->meter_box_id);
        $this->assertSame(1, SubscriptionBulkChange::sole()->changed_count);
        $this->assertDatabaseCount('subscription_bulk_change_items', 1);
    }

    #[DataProvider('permissionCases')]
    public function test_bulk_move_requires_both_edit_and_bulk_permissions(array $permissions, bool $allowed): void
    {
        $actor = User::factory()->collector()->create();
        $actor->permissions()->sync(Permission::idsFor($permissions));
        $actor->unsetRelation('permissions');

        $this->assertSame($allowed, $actor->can('bulkUpdate', [Subscription::class, 'meter_box_id']));
    }

    public static function permissionCases(): array
    {
        return [
            'no permissions' => [[], false],
            'edit alone' => [[PermissionKey::UpdateSubscriptions], false],
            'bulk alone' => [[PermissionKey::BulkUpdateSubscriptions], false],
            'both' => [[PermissionKey::UpdateSubscriptions, PermissionKey::BulkUpdateSubscriptions], true],
        ];
    }

    public function test_guests_and_users_without_the_required_permissions_cannot_move_subscriptions(): void
    {
        $actor = User::factory()->collector()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => null]);
        $target = MeterBox::factory()->create(['branch_id' => $actor->branch_id]);
        $payload = ['field' => 'meter_box_id', 'value' => $target->id, 'ids' => [$subscription->id], 'expected_count' => 1];

        $this->post(route('subscriptions.bulk-changes.store'), $payload)->assertRedirect(route('login'));
        $this->actingAs($actor)->post(route('subscriptions.bulk-changes.store'), $payload)->assertForbidden();

        $this->assertNull($subscription->fresh()->meter_box_id);
        $this->assertDatabaseCount('subscription_bulk_changes', 0);
    }

    public function test_a_foreign_or_missing_target_is_rejected_without_moving_anything(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => null]);
        $foreignBox = MeterBox::factory()->create();

        $this->actingAs($actor)->post(route('subscriptions.bulk-changes.store'), [
            'field' => 'meter_box_id', 'value' => $foreignBox->id, 'ids' => [$subscription->id], 'expected_count' => 1,
        ])->assertSessionHasErrors(['value' => 'اختر طبلونًا متاحًا ضمن فرع الاشتراكات المحددة.']);
        $this->post(route('subscriptions.bulk-changes.store'), [
            'field' => 'meter_box_id', 'value' => $foreignBox->id + 100, 'ids' => [$subscription->id], 'expected_count' => 1,
        ])->assertSessionHasErrors('value');

        $this->assertNull($subscription->fresh()->meter_box_id);
        $this->assertDatabaseCount('subscription_bulk_changes', 0);
    }

    public function test_a_mixed_branch_selection_is_rejected_in_full_even_for_the_super_admin(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $first = Subscription::factory()->create(['meter_box_id' => null]);
        $foreign = Subscription::factory()->create(['meter_box_id' => null]);
        $target = MeterBox::factory()->create(['branch_id' => $first->branch_id]);

        $this->actingAs($actor)->post(route('subscriptions.bulk-changes.store'), [
            'field' => 'meter_box_id', 'value' => $target->id, 'ids' => [$first->id, $foreign->id], 'expected_count' => 2,
        ])->assertSessionHasErrors(['value' => 'النقل متاح داخل الفرع نفسه فقط. اختر اشتراكات وطبلونًا من الفرع نفسه.']);

        $this->assertNull($first->fresh()->meter_box_id);
        $this->assertNull($foreign->fresh()->meter_box_id);
        $this->assertDatabaseCount('subscription_bulk_changes', 0);
    }

    public function test_a_hidden_subscription_in_the_selection_prevents_a_partial_move(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $own = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => null]);
        $foreign = Subscription::factory()->create(['meter_box_id' => null]);
        $target = MeterBox::factory()->create(['branch_id' => $actor->branch_id]);

        $this->actingAs($actor)->post(route('subscriptions.bulk-changes.store'), [
            'field' => 'meter_box_id', 'value' => $target->id, 'ids' => [$own->id, $foreign->id], 'expected_count' => 2,
        ])->assertSessionHasErrors('ids');

        $this->assertNull($own->fresh()->meter_box_id);
        $this->assertDatabaseCount('subscription_bulk_changes', 0);
    }

    public function test_target_selection_and_reviewed_count_are_required(): void
    {
        $actor = User::factory()->branchAdmin()->create();

        $this->actingAs($actor)->post(route('subscriptions.bulk-changes.store'), ['field' => 'meter_box_id'])
            ->assertSessionHasErrors(['value', 'ids', 'expected_count']);

        $this->assertDatabaseCount('subscription_bulk_changes', 0);
    }

    public function test_a_changed_reviewed_count_stops_the_move_and_an_already_matching_selection_does_not_create_a_log(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $target = MeterBox::factory()->create(['branch_id' => $actor->branch_id]);
        $subscription = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $target->id]);

        $this->actingAs($actor)->post(route('subscriptions.bulk-changes.store'), [
            'field' => 'meter_box_id', 'value' => $target->id, 'ids' => [$subscription->id], 'expected_count' => 2,
        ])->assertSessionHasErrors('ids');
        $this->post(route('subscriptions.bulk-changes.store'), [
            'field' => 'meter_box_id', 'value' => $target->id, 'ids' => [$subscription->id], 'expected_count' => 1,
        ])->assertSessionHasErrors(['ids' => 'كل الاشتراكات المحددة موجودة في هذا الطبلون بالفعل.']);

        $this->assertDatabaseCount('subscription_bulk_changes', 0);
    }

    public function test_undo_restores_original_boxes_and_unassigned_state_but_keeps_later_moves(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $old = MeterBox::factory()->create(['branch_id' => $actor->branch_id]);
        $target = MeterBox::factory()->create(['branch_id' => $actor->branch_id]);
        $first = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $old->id]);
        $unassigned = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => null]);
        $movedAgain = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $old->id]);
        $this->actingAs($actor)->post(route('subscriptions.bulk-changes.store'), [
            'field' => 'meter_box_id', 'value' => $target->id, 'ids' => [$first->id, $unassigned->id, $movedAgain->id], 'expected_count' => 3,
        ])->assertSessionHasNoErrors();
        $movedAgain->refresh()->update(['meter_box_id' => $old->id]);
        $change = SubscriptionBulkChange::sole();

        $this->post(route('subscriptions.bulk-changes.undo', $change))->assertSessionHasNoErrors()->assertSessionHas('status', 'subscriptions-bulk-undone');

        $this->assertSame($old->id, $first->fresh()->meter_box_id);
        $this->assertNull($unassigned->fresh()->meter_box_id);
        $this->assertSame($old->id, $movedAgain->fresh()->meter_box_id);
        $this->assertSame(2, $change->fresh()->restored_count);
        $this->post(route('subscriptions.bulk-changes.undo', $change))->assertSessionHasErrors('undo');
    }

    public function test_undo_skips_a_deleted_original_box(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $old = MeterBox::factory()->create(['branch_id' => $actor->branch_id]);
        $target = MeterBox::factory()->create(['branch_id' => $actor->branch_id]);
        $subscription = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $old->id]);
        $this->actingAs($actor)->post(route('subscriptions.bulk-changes.store'), [
            'field' => 'meter_box_id', 'value' => $target->id, 'ids' => [$subscription->id], 'expected_count' => 1,
        ])->assertSessionHasNoErrors();
        $old->delete();
        $change = SubscriptionBulkChange::sole();

        $this->post(route('subscriptions.bulk-changes.undo', $change))->assertSessionHasNoErrors();

        $this->assertSame($target->id, $subscription->fresh()->meter_box_id);
        $this->assertSame(0, $change->fresh()->restored_count);
    }
}
