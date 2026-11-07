<?php

namespace Tests\Feature;

use App\Enums\MessageKind;
use App\Enums\PermissionKey;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\MeterReading;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionBulkChange;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\Messaging\MessageComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SubscriptionArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function archivist(): User
    {
        $user = User::factory()->create();
        $user->permissions()->sync(Permission::idsFor([PermissionKey::ViewSubscriptions, PermissionKey::ArchiveSubscriptions, PermissionKey::ViewArchivedSubscriptions, PermissionKey::RestoreArchivedSubscriptions]));

        return $user;
    }

    private function endedSubscription(User $user): Subscription
    {
        return Subscription::factory()->create(['branch_id' => $user->branch_id ?? Branch::factory()->create()->id, 'status' => SubscriptionStatus::Disconnected]);
    }

    /** @return array{service_ended_on: string, reason: string, confirmed: bool} */
    private function archiveData(): array
    {
        return ['service_ended_on' => '2026-10-20', 'reason' => 'أنهى المشترك الخدمة نهائيًا', 'confirmed' => true];
    }

    public function test_archiving_preserves_financial_lines_readings_and_siblings(): void
    {
        $this->travelTo('2026-10-21 10:00:00');
        $user = $this->archivist();
        $subscription = $this->endedSubscription($user);
        $sibling = Subscription::factory()->create(['subscriber_profile_id' => $subscription->subscriber_profile_id, 'branch_id' => $user->branch_id]);
        $reading = MeterReading::factory()->approved()->create(['subscription_id' => $subscription->id]);
        $charge = SubscriptionTransaction::factory()->create(['subscription_id' => $subscription->id, 'amount' => '100.00']);
        $payment = SubscriptionTransaction::factory()->create(['subscription_id' => $subscription->id, 'type' => SubscriptionTransaction::TYPE_PAYMENT, 'source_key' => 'payment:archive-test', 'amount' => '-30.00']);
        $chargeBefore = $charge->fresh()->getRawOriginal();
        $paymentBefore = $payment->fresh()->getRawOriginal();

        $this->actingAs($user)->post(route('subscriptions.archive.store', $subscription), $this->archiveData())->assertSessionHasNoErrors()->assertSessionHas('status', 'subscription-archived');

        $this->assertSame(70.0, $subscription->fresh()->balance());
        $this->assertSame($chargeBefore, $charge->fresh()->getRawOriginal());
        $this->assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
        $this->assertModelExists($reading);
        $this->assertNull($sibling->fresh()->archived_at);
        $this->assertSame(SubscriptionStatus::Active, $sibling->fresh()->status);
        $this->assertSame($user->id, $subscription->fresh()->archived_by);
        $this->assertSame('2026-10-20', $subscription->fresh()->service_ended_on->toDateString());
        $this->assertSame($user->id, $subscription->fresh()->archive_history[0]['user_id']);
        $this->get(route('subscriptions.statement', $subscription))->assertOk()->assertInertia(fn ($page) => $page->where('summary.balance', '70.00')->has('entries', 2)->where('subscription.statusLabel', 'مؤرشف'));
    }

    public function test_archive_list_is_searchable_and_separate_from_daily_list(): void
    {
        $this->travelTo('2026-10-21 10:00:00');
        $user = $this->archivist();
        $subscription = $this->endedSubscription($user);
        $this->actingAs($user)->post(route('subscriptions.archive.store', $subscription), $this->archiveData())->assertSessionHasNoErrors();
        $this->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page->has('subscriptions.data', 0)->where('canViewArchived', true));
        $this->get(route('subscriptions.index', ['filter' => ['archived' => '1'], 'search' => $subscription->account_number]))->assertInertia(fn ($page) => $page->has('subscriptions.data', 1)->where('showArchived', true)->where('subscriptions.data.0.id', $subscription->id)->where('subscriptions.data.0.canUpdate', false)->where('subscriptions.data.0.canDelete', false));
    }

    #[TestWith(['active'])]
    #[TestWith(['suspended'])]
    public function test_only_disconnected_subscriptions_can_be_archived(string $status): void
    {
        $this->travelTo('2026-10-21 10:00:00');
        $user = $this->archivist();
        $subscription = $this->endedSubscription($user);
        $subscription->update(['status' => $status]);
        $this->actingAs($user)->post(route('subscriptions.archive.store', $subscription), $this->archiveData())->assertSessionHasErrors('archive');
        $this->assertNull($subscription->fresh()->archived_at);
    }

    #[TestWith(['pending', false])]
    #[TestWith(['approved', true])]
    public function test_readings_must_be_completed_before_archiving(string $status, bool $requiresReentry): void
    {
        $this->travelTo('2026-10-21 10:00:00');
        $user = $this->archivist();
        $subscription = $this->endedSubscription($user);
        MeterReading::factory()->create(['subscription_id' => $subscription->id, 'status' => $status, 'requires_reentry' => $requiresReentry]);
        $this->actingAs($user)->post(route('subscriptions.archive.store', $subscription), $this->archiveData())->assertSessionHasErrors('archive');
        $this->assertNull($subscription->fresh()->archived_at);
    }

    #[TestWith(['reason', ''])]
    #[TestWith(['confirmed', false])]
    #[TestWith(['service_ended_on', '2026-10-22'])]
    #[TestWith(['service_ended_on', 'invalid'])]
    public function test_details_and_confirmation_are_required(string $field, mixed $value): void
    {
        $this->travelTo('2026-10-21 10:00:00');
        $user = $this->archivist();
        $subscription = $this->endedSubscription($user);
        $this->actingAs($user)->post(route('subscriptions.archive.store', $subscription), [...$this->archiveData(), $field => $value])->assertSessionHasErrors($field);
        $this->assertNull($subscription->fresh()->archived_at);
    }

    public function test_edit_and_delete_permissions_do_not_grant_archiving_or_archive_access(): void
    {
        $user = User::factory()->dataEntry()->create();
        $user->permissions()->attach(Permission::idsFor([PermissionKey::DeleteSubscriptions]));
        $subscription = $this->endedSubscription($user);
        $this->actingAs($user)->post(route('subscriptions.archive.store', $subscription), $this->archiveData())->assertForbidden();
        $this->get(route('subscriptions.index', ['filter' => ['archived' => '1']]))->assertForbidden();
        $this->assertNull($subscription->fresh()->archived_at);
    }

    public function test_archive_and_restore_are_scoped_to_the_employees_branch(): void
    {
        $this->travelTo('2026-10-21 10:00:00');
        $user = $this->archivist();
        $foreign = Subscription::factory()->create(['status' => SubscriptionStatus::Disconnected]);
        $this->actingAs($user)->post(route('subscriptions.archive.store', $foreign), $this->archiveData())->assertForbidden();
        $this->actingAs(User::factory()->superAdmin()->create())->post(route('subscriptions.archive.store', $foreign), $this->archiveData())->assertSessionHasNoErrors();
        $this->actingAs($user)->delete(route('subscriptions.archive.destroy', $foreign), ['reason' => 'استعادة', 'confirmed' => true])->assertForbidden();
        $this->get(route('subscriptions.index', ['filter' => ['archived' => '1']]))->assertInertia(fn ($page) => $page->has('subscriptions.data', 0));
        $this->get(route('subscriptions.statement', $foreign))->assertForbidden();
        $this->assertNotNull($foreign->fresh()->archived_at);
    }

    public function test_restore_keeps_history_and_requires_its_own_permission_without_reactivating(): void
    {
        $this->travelTo('2026-10-21 10:00:00');
        $user = $this->archivist();
        $subscription = $this->endedSubscription($user);
        $this->actingAs($user)->post(route('subscriptions.archive.store', $subscription), $this->archiveData())->assertSessionHasNoErrors();
        $this->post(route('subscriptions.archive.store', $subscription), $this->archiveData())->assertSessionHasErrors('archive');
        $this->delete(route('subscriptions.archive.destroy', $subscription), ['reason' => '', 'confirmed' => false])->assertSessionHasErrors(['reason', 'confirmed']);
        $user->permissions()->detach(Permission::idsFor([PermissionKey::RestoreArchivedSubscriptions]));
        $this->actingAs($user->fresh())->delete(route('subscriptions.archive.destroy', $subscription), ['reason' => 'استعادة', 'confirmed' => true])->assertForbidden();
        $this->assertCount(1, $subscription->fresh()->archive_history);
        $this->actingAs(User::factory()->superAdmin()->create())->delete(route('subscriptions.archive.destroy', $subscription), ['reason' => 'مراجعة الملف', 'confirmed' => true])->assertSessionHasNoErrors()->assertSessionHas('status', 'subscription-restored');
        $this->assertNull($subscription->fresh()->archived_at);
        $this->assertSame(SubscriptionStatus::Disconnected, $subscription->fresh()->status);
        $this->assertCount(2, $subscription->fresh()->archive_history);
        $this->assertSame('مراجعة الملف', $subscription->fresh()->archive_history[1]['reason']);
        $this->delete(route('subscriptions.destroy', $subscription))->assertForbidden();
        $this->delete(route('subscriptions.archive.destroy', $subscription), ['reason' => 'تكرار', 'confirmed' => true])->assertSessionHasErrors('archive');
    }

    public function test_archived_subscriptions_cannot_be_deleted_edited_or_reactivated_by_bulk_changes(): void
    {
        $this->travelTo('2026-10-21 10:00:00');
        $user = User::factory()->superAdmin()->create();
        $subscription = Subscription::factory()->create();
        $this->actingAs($user)->post(route('subscriptions.bulk-changes.store'), ['field' => 'status', 'value' => 'disconnected', 'ids' => [$subscription->id]])->assertSessionHasNoErrors();
        $change = SubscriptionBulkChange::query()->sole();
        $this->post(route('subscriptions.archive.store', $subscription), $this->archiveData())->assertSessionHasNoErrors();
        $this->delete(route('subscriptions.destroy', $subscription))->assertForbidden();
        $this->put(route('subscriptions.update', $subscription), ['status' => 'active'])->assertForbidden();
        $this->post(route('subscriptions.bulk-changes.store'), ['field' => 'status', 'value' => 'active', 'ids' => [$subscription->id]])->assertSessionHasErrors('ids');
        $this->post(route('subscriptions.bulk-changes.undo', $change))->assertSessionHasNoErrors();
        $this->assertModelExists($subscription);
        $this->assertSame(SubscriptionStatus::Disconnected, $subscription->fresh()->status);
        $this->assertNotNull($subscription->fresh()->archived_at);
        $this->assertSame(0, $change->fresh()->restored_count);
    }

    public function test_archived_accounts_are_excluded_from_message_recipients_even_when_selected(): void
    {
        $this->travelTo('2026-10-21 10:00:00');
        $user = $this->archivist();
        $subscription = $this->endedSubscription($user);
        $this->actingAs($user)->post(route('subscriptions.archive.store', $subscription), $this->archiveData())->assertSessionHasNoErrors();
        $this->assertCount(0, app(MessageComposer::class)->recipients(MessageKind::Custom, $user, ['subscription_ids' => [$subscription->id]]));
        $user->permissions()->detach(Permission::idsFor([PermissionKey::ViewArchivedSubscriptions]));
        $this->actingAs($user->fresh())->get(route('subscriptions.statement', $subscription))->assertForbidden();
    }

    public function test_archived_accounts_allow_authorized_collection_but_no_new_charges_or_reading_edits(): void
    {
        $this->travelTo('2026-10-21 10:00:00');
        $user = User::factory()->superAdmin()->create();
        $subscription = $this->endedSubscription($user);
        $reading = MeterReading::factory()->approved()->create(['subscription_id' => $subscription->id]);
        SubscriptionTransaction::factory()->create(['subscription_id' => $subscription->id, 'amount' => '100.00']);
        $this->actingAs($user)->post(route('subscriptions.archive.store', $subscription), $this->archiveData())->assertSessionHasNoErrors();
        $this->post(route('subscriptions.payments.store', $subscription), ['amount' => '25.00', 'currency' => 'ILS', 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame(75.0, $subscription->fresh()->balance());
        $this->assertFalse($user->can('addCharge', $subscription->fresh()));
        $this->assertFalse($user->can('update', $reading->fresh()));
        $this->assertFalse($user->can('updateAsLastReading', $reading->fresh()));
        $this->assertFalse($user->can('updateUnitPrice', $reading->fresh()));
        $this->assertFalse($user->can('updateMinimumPayment', $reading->fresh()));
    }

    public function test_archived_accounts_are_hidden_from_mobile_search_and_require_archive_permission_for_messages(): void
    {
        $this->travelTo('2026-10-21 10:00:00');
        $user = $this->archivist();
        $subscription = $this->endedSubscription($user);
        $this->actingAs($user)->post(route('subscriptions.archive.store', $subscription), $this->archiveData())->assertSessionHasNoErrors();
        $token = $this->postJson(route('mobile.login'), ['username' => $user->username, 'password' => 'password'])->assertOk()->json('token');
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson(route('mobile.collections.subscriptions', ['search' => $subscription->account_number]))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('mobile.collections.subscriptions.show', $subscription))->assertNotFound();
        $user->permissions()->sync(Permission::idsFor([PermissionKey::ViewSubscriptions, PermissionKey::ViewMessages]));
        $this->actingAs($user->fresh())->getJson(route('subscriptions.messages.index', $subscription))->assertForbidden();
    }
}
