<?php

namespace Tests\Feature;

use App\Enums\MessageChannel;
use App\Enums\MessageKind;
use App\Enums\MessageStatus;
use App\Enums\PermissionKey;
use App\Models\MessageBatch;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SubscriptionMessageHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_shows_actual_message_text_kind_channel_status_and_sending_details_for_this_subscription_only(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $actor->branch_id]);
        $other = Subscription::factory()->create(['branch_id' => $actor->branch_id]);
        $batch = MessageBatch::factory()->create(['branch_id' => $actor->branch_id, 'kind' => MessageKind::WeeklyReading, 'channel' => MessageChannel::Sms, 'created_by' => $actor->id]);
        $message = SubscriptionMessage::factory()->for($subscription)->create([
            'message_batch_id' => $batch->id, 'body' => 'قراءتك 210 والاستهلاك 10 كيلو', 'phone' => '0591234567',
            'status' => MessageStatus::Sent, 'sent_at' => '2026-10-20 10:30:00', 'sent_by' => $actor->id,
        ]);
        SubscriptionMessage::factory()->for($other)->create(['message_batch_id' => $batch->id, 'body' => 'رسالة لمشترك آخر']);
        Queue::fake();

        $this->actingAs($actor)->getJson(route('subscriptions.messages.index', $subscription))
            ->assertJsonPath('messages.total', 1)
            ->assertJsonPath('messages.data.0.id', $message->id)
            ->assertJsonPath('messages.data.0.body', 'قراءتك 210 والاستهلاك 10 كيلو')
            ->assertJsonPath('messages.data.0.phone', '0591234567')
            ->assertJsonPath('messages.data.0.kind', 'weekly_reading')
            ->assertJsonPath('messages.data.0.kindLabel', __(MessageKind::WeeklyReading->label()))
            ->assertJsonPath('messages.data.0.channel', 'sms')
            ->assertJsonPath('messages.data.0.status', 'sent')
            ->assertJsonPath('messages.data.0.createdBy', $actor->name)
            ->assertJsonPath('messages.data.0.sentBy', $actor->name)
            ->assertJsonPath('messages.data.0.sentAt', $message->sent_at->toIso8601String())
            ->assertJsonCount(4, 'kinds')
            ->assertJsonCount(3, 'statuses');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('subscription_messages', 2);
    }

    public function test_history_requires_message_permission_and_hides_other_branches(): void
    {
        $actor = User::factory()->collector()->create();
        $actor->permissions()->sync([]);
        $own = Subscription::factory()->create(['branch_id' => $actor->branch_id]);
        $foreign = Subscription::factory()->create();

        $this->getJson(route('subscriptions.messages.index', $own))->assertUnauthorized();
        $this->actingAs($actor)->getJson(route('subscriptions.messages.index', $own))->assertForbidden();
        $actor->permissions()->sync(Permission::idsFor([PermissionKey::ViewMessages]));
        $actor->unsetRelation('permissions');

        $this->getJson(route('subscriptions.messages.index', $own))->assertJsonPath('messages.total', 0);
        $this->getJson(route('subscriptions.messages.index', $foreign))->assertNotFound();
    }

    public function test_a_super_admin_can_view_a_subscriptions_history_in_any_branch(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $subscription = Subscription::factory()->create();
        SubscriptionMessage::factory()->for($subscription)->create();

        $this->actingAs($actor)->getJson(route('subscriptions.messages.index', $subscription))->assertJsonPath('messages.total', 1);
    }

    public function test_type_and_status_filters_include_disconnection_warnings_and_preserve_failure_details(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $actor->branch_id]);
        foreach (MessageKind::cases() as $kind) {
            $batch = MessageBatch::factory()->create(['branch_id' => $actor->branch_id, 'kind' => $kind, 'channel' => MessageChannel::WhatsApp]);
            SubscriptionMessage::factory()->for($subscription)->failed()->create(['message_batch_id' => $batch->id]);
        }
        $sent = SubscriptionMessage::factory()->for($subscription)->create(['status' => MessageStatus::Sent]);

        $this->actingAs($actor)->getJson(route('subscriptions.messages.index', [$subscription, 'kind' => 'disconnection_warning', 'status' => 'failed']))
            ->assertJsonPath('messages.total', 1)
            ->assertJsonPath('messages.data.0.kind', 'disconnection_warning')
            ->assertJsonPath('messages.data.0.channel', 'whatsapp')
            ->assertJsonPath('messages.data.0.error', 'Gateway error');
        $this->getJson(route('subscriptions.messages.index', [$subscription, 'status' => 'sent']))
            ->assertJsonPath('messages.total', 1)->assertJsonPath('messages.data.0.id', $sent->id);
        $this->getJson(route('subscriptions.messages.index', [$subscription, 'status' => 'pending']))->assertJsonPath('messages.total', 0);
    }

    public function test_history_is_paginated_newest_first_and_rejects_invalid_filters(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $actor->branch_id]);
        $batch = MessageBatch::factory()->create(['branch_id' => $actor->branch_id]);
        $messages = SubscriptionMessage::factory()->count(16)->for($subscription)->create(['message_batch_id' => $batch->id]);

        $this->actingAs($actor)->getJson(route('subscriptions.messages.index', $subscription))
            ->assertJsonPath('messages.total', 16)->assertJsonCount(15, 'messages.data')->assertJsonPath('messages.data.0.id', $messages->last()->id);
        $this->getJson(route('subscriptions.messages.index', [$subscription, 'page' => 2]))
            ->assertJsonCount(1, 'messages.data')->assertJsonPath('messages.data.0.id', $messages->first()->id);
        $this->getJson(route('subscriptions.messages.index', [$subscription, 'kind' => 'unknown', 'status' => 'unknown', 'page' => 0]))
            ->assertUnprocessable()->assertJsonValidationErrors(['kind', 'status', 'page']);
    }

    public function test_a_branch_can_read_its_own_message_from_a_company_wide_batch_without_seeing_other_recipients(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $actor->branch_id]);
        $batch = MessageBatch::factory()->create(['branch_id' => null]);
        $ownMessage = SubscriptionMessage::factory()->for($subscription)->create(['message_batch_id' => $batch->id]);
        SubscriptionMessage::factory()->create(['message_batch_id' => $batch->id]);

        $this->actingAs($actor)->getJson(route('subscriptions.messages.index', $subscription))
            ->assertJsonPath('messages.total', 1)->assertJsonPath('messages.data.0.id', $ownMessage->id);
    }
}
