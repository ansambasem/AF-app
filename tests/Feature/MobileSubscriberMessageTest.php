<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Jobs\SendSubscriptionMessage;
use App\Models\MessageBatch;
use App\Models\MobileAccessToken;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MobileSubscriberMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_collector_queues_one_message_to_the_registered_phone_and_retries_without_duplicates(): void
    {
        Http::preventStrayRequests();
        Queue::fake([SendSubscriptionMessage::class]);
        config(['services.sms.driver' => 'http']);
        $collector = $this->collector();
        $subscription = Subscription::factory()->create([
            'branch_id' => $collector->branch_id, 'full_name' => 'أحمد',
            'phone' => '0599000111', 'subscription_phone' => '0599000222',
        ]);
        $payload = ['mobile_operation_id' => (string) Str::uuid(), 'body' => 'مرحباً {الاسم}', 'phone' => '0599000999'];
        $url = route('mobile.collections.subscriptions.messages.store', $subscription);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));
        $id = $this->postJson($url, $payload)->assertCreated()
            ->assertJsonPath('status', 'pending')->assertJsonPath('phone', '0599000222')->json('id');
        $this->postJson($url, $payload)->assertCreated()->assertJsonPath('id', $id);

        $this->assertDatabaseCount('message_batches', 1);
        $this->assertDatabaseCount('subscription_messages', 1);
        $this->assertDatabaseHas('message_batches', ['created_by' => $collector->id, 'branch_id' => $collector->branch_id, 'channel' => 'sms', 'kind' => 'custom']);
        $this->assertDatabaseHas('subscription_messages', ['id' => $id, 'subscription_id' => $subscription->id, 'phone' => '0599000222', 'body' => 'مرحباً أحمد', 'status' => 'pending']);
        Queue::assertPushedOnce(SendSubscriptionMessage::class);
        Http::assertNothingSent();
    }

    public function test_message_sending_requires_authentication_send_permission_and_the_visible_branch(): void
    {
        Queue::fake([SendSubscriptionMessage::class]);
        $collector = $this->collector();
        $own = Subscription::factory()->create(['branch_id' => $collector->branch_id]);
        $other = Subscription::factory()->create();
        $payload = ['mobile_operation_id' => (string) Str::uuid(), 'body' => 'مرحباً'];
        $url = route('mobile.collections.subscriptions.messages.store', $own);

        $this->postJson($url, $payload)->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));
        $this->postJson(route('mobile.collections.subscriptions.messages.store', $other), $payload)->assertNotFound();
        $collector->permissions()->sync(Permission::idsFor([PermissionKey::RecordCollections, PermissionKey::RecordCashPayments]));
        $this->postJson($url, $payload)->assertForbidden();
        $this->getJson(route('mobile.collections.subscriptions.show', $own))->assertJsonPath('messaging.can_send', false);

        $this->assertDatabaseCount('message_batches', 0);
        $this->assertDatabaseCount('subscription_messages', 0);
        Queue::assertNothingPushed();
    }

    #[TestWith(['', 'اكتب نص الرسالة أولاً.'])]
    #[TestWith([null, 'اكتب نص الرسالة أولاً.'])]
    public function test_empty_messages_are_rejected_without_queuing(?string $body, string $error): void
    {
        Queue::fake([SendSubscriptionMessage::class]);
        $collector = $this->collector();
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id]);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector))
            ->postJson(route('mobile.collections.subscriptions.messages.store', $subscription), ['mobile_operation_id' => (string) Str::uuid(), 'body' => $body])
            ->assertUnprocessable()->assertJsonPath('errors.body.0', $error);

        $this->assertDatabaseCount('subscription_messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_invalid_operations_and_overlong_messages_are_rejected(): void
    {
        Queue::fake([SendSubscriptionMessage::class]);
        $collector = $this->collector();
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id]);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector))
            ->postJson(route('mobile.collections.subscriptions.messages.store', $subscription), ['mobile_operation_id' => 'bad-id', 'body' => str_repeat('أ', 1001)])
            ->assertUnprocessable()->assertJsonValidationErrors(['mobile_operation_id', 'body'])
            ->assertJsonPath('errors.body.0', 'الرسالة يجب ألا تتجاوز 1000 حرف.');

        $this->assertDatabaseCount('subscription_messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_missing_phone_and_disabled_sms_are_reported_without_false_success(): void
    {
        Queue::fake([SendSubscriptionMessage::class]);
        $collector = $this->collector();
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id, 'phone' => null, 'subscription_phone' => null]);
        $payload = ['mobile_operation_id' => (string) Str::uuid(), 'body' => 'مرحباً'];
        $url = route('mobile.collections.subscriptions.messages.store', $subscription);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('phone');
        $subscription->update(['phone' => '0599000111']);
        config(['services.sms.driver' => 'log']);
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('sms');
        $this->getJson(route('mobile.collections.subscriptions.show', $subscription))
            ->assertJsonPath('messaging.can_send', true)->assertJsonPath('messaging.sms_available', false);

        $this->assertDatabaseCount('message_batches', 0);
        Queue::assertNothingPushed();
    }

    public function test_an_operation_cannot_be_reused_for_a_different_body_recipient_or_collector(): void
    {
        Queue::fake([SendSubscriptionMessage::class]);
        config(['services.sms.driver' => 'http']);
        $collector = $this->collector();
        $own = Subscription::factory()->create(['branch_id' => $collector->branch_id, 'phone' => '0599000111']);
        $other = Subscription::factory()->create(['branch_id' => $collector->branch_id]);
        $payload = ['mobile_operation_id' => (string) Str::uuid(), 'body' => 'مرحباً'];
        $url = route('mobile.collections.subscriptions.messages.store', $own);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));
        $this->postJson($url, $payload)->assertCreated();
        $this->postJson($url, [...$payload, 'body' => 'رسالة أخرى'])->assertConflict();
        $this->postJson(route('mobile.collections.subscriptions.messages.store', $other), $payload)->assertConflict();
        $secondCollector = $this->collector();
        $secondCollector->update(['branch_id' => $collector->branch_id]);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($secondCollector));
        $this->postJson($url, $payload)->assertForbidden();

        $this->assertDatabaseCount('message_batches', 1);
        $this->assertDatabaseCount('subscription_messages', 1);
        Queue::assertPushedOnce(SendSubscriptionMessage::class);
    }

    public function test_message_only_staff_can_find_and_message_their_branch_without_access_to_collection_data(): void
    {
        Queue::fake([SendSubscriptionMessage::class]);
        config(['services.sms.driver' => 'http']);
        $staff = User::factory()->collector()->create();
        $staff->permissions()->sync(Permission::idsFor([PermissionKey::SendMessages]));
        $recipient = Subscription::factory()->create(['branch_id' => $staff->branch_id, 'full_name' => 'أحمد', 'phone' => '0599000111']);
        Subscription::factory()->create(['full_name' => 'مشترك آخر']);
        $this->withToken(MobileAccessToken::issue($staff));

        $this->getJson(route('mobile.me'))->assertOk()->assertJsonPath('user.can_send_messages', true)
            ->assertJsonPath('user.can_record_collections', false);
        $this->getJson(route('mobile.messages.subscriptions'))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recipient->id)->assertJsonPath('sms_available', true)
            ->assertJsonMissingPath('data.0.balance')->assertJsonMissingPath('data.0.transactions');
        $this->getJson(route('mobile.collections.subscriptions.show', $recipient))->assertForbidden();
        $this->postJson(route('mobile.collections.subscriptions.messages.store', $recipient), [
            'mobile_operation_id' => (string) Str::uuid(), 'body' => 'مرحباً أحمد',
        ])->assertCreated();

        $this->assertDatabaseHas('subscription_messages', ['subscription_id' => $recipient->id, 'body' => 'مرحباً أحمد']);
        Queue::assertPushedOnce(SendSubscriptionMessage::class);
    }

    public function test_recipient_search_is_authenticated_searchable_and_revocable(): void
    {
        $staff = $this->collector();
        $recipient = Subscription::factory()->create(['branch_id' => $staff->branch_id, 'full_name' => 'أحمد علي']);
        Subscription::factory()->create(['branch_id' => $staff->branch_id, 'full_name' => 'محمد']);
        $url = route('mobile.messages.subscriptions');
        $this->getJson($url)->assertUnauthorized();
        $this->withToken(MobileAccessToken::issue($staff));
        $this->getJson($url.'?search='.urlencode('احمد'))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recipient->id);
        $staff->permissions()->sync([]);
        $this->getJson($url)->assertForbidden();
        $this->getJson(route('mobile.me'))->assertJsonPath('user.can_send_messages', false);
        $this->postJson(route('mobile.collections.subscriptions.messages.store', $recipient), [
            'mobile_operation_id' => (string) Str::uuid(), 'body' => 'مرحباً',
        ])->assertForbidden();
        $this->assertDatabaseCount('subscription_messages', 0);
    }

    #[TestWith(['weekly', 'أسبوعي'])]
    #[TestWith(['monthly', 'شهري'])]
    public function test_mobile_accounting_types_are_always_arabic(string $type, string $label): void
    {
        app()->setLocale('en');
        $staff = $this->collector();
        $subscriber = Subscription::factory()->create(['branch_id' => $staff->branch_id, 'accounting_type' => $type]);
        $this->withToken(MobileAccessToken::issue($staff))->getJson(route('mobile.collections.subscriptions.show', $subscriber))
            ->assertOk()->assertJsonPath('subscription.accounting_type_label', $label);
    }

    public function test_mobile_message_history_is_read_only_permission_scoped_and_branch_scoped(): void
    {
        $staff = User::factory()->collector()->create();
        $staff->permissions()->sync(Permission::idsFor([PermissionKey::ViewMessages]));
        $subscriber = Subscription::factory()->create(['branch_id' => $staff->branch_id]);
        $other = Subscription::factory()->create();
        $batch = MessageBatch::factory()->create(['branch_id' => $staff->branch_id, 'created_by' => $staff->id]);
        $message = SubscriptionMessage::factory()->for($subscriber)->create(['message_batch_id' => $batch->id, 'body' => 'رسالة سابقة']);
        $url = route('mobile.collections.subscriptions.messages.index', $subscriber);

        $this->getJson($url)->assertUnauthorized();
        $this->withToken(MobileAccessToken::issue($staff))->getJson($url)->assertOk()
            ->assertJsonCount(1, 'messages.data')->assertJsonPath('messages.data.0.id', $message->id)
            ->assertJsonPath('messages.data.0.body', 'رسالة سابقة');
        $this->getJson(route('mobile.collections.subscriptions.messages.index', $other))->assertNotFound();
        $this->getJson(route('mobile.messages.subscriptions'))->assertOk()->assertJsonPath('can_send', false)->assertJsonPath('can_view_history', true);
        $this->getJson(route('mobile.me'))->assertJsonPath('user.can_send_messages', false)->assertJsonPath('user.can_view_messages', true);
        $this->postJson(route('mobile.collections.subscriptions.messages.store', $subscriber), ['mobile_operation_id' => (string) Str::uuid(), 'body' => 'لا ترسل'])
            ->assertForbidden();
        $staff->permissions()->sync([]);
        $this->getJson($url)->assertForbidden();
        $this->assertDatabaseCount('subscription_messages', 1);
    }

    public function test_subscriber_details_expose_separate_sending_and_history_capabilities(): void
    {
        $staff = $this->collector();
        $staff->permissions()->sync(Permission::idsFor([PermissionKey::RecordCollections, PermissionKey::RecordCashPayments, PermissionKey::ViewMessages]));
        $subscriber = Subscription::factory()->create(['branch_id' => $staff->branch_id]);
        $this->withToken(MobileAccessToken::issue($staff))->getJson(route('mobile.collections.subscriptions.show', $subscriber))
            ->assertOk()->assertJsonPath('messaging.can_send', false)->assertJsonPath('messaging.can_view_history', true);
    }

    private function collector(): User
    {
        $collector = User::factory()->collector()->create();
        $collector->permissions()->sync(Permission::idsFor([PermissionKey::RecordCollections, PermissionKey::RecordCashPayments, PermissionKey::SendMessages]));

        return $collector;
    }
}
