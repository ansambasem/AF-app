<?php

namespace Tests\Feature\Messages;

use App\Enums\MessageChannel;
use App\Enums\MessageKind;
use App\Enums\MessageStatus;
use App\Enums\PermissionKey;
use App\Enums\SubscriptionStatus;
use App\Jobs\SendSubscriptionMessage;
use App\Models\MessageBatch;
use App\Models\MeterReading;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionMessage;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MessageTest extends TestCase
{
    use RefreshDatabase;

    private function weekStart(): string
    {
        return MeterReading::latestEndedWeekStart(null)->toDateString();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('messages.index'))->assertRedirect(route('login'));
    }

    public function test_a_user_without_the_messages_permissions_cannot_open_or_send_messages(): void
    {
        $collector = User::factory()->collector()->create();

        $this->actingAs($collector)->get(route('messages.index'))->assertForbidden();
        $this->actingAs($collector)->get(route('messages.create'))->assertForbidden();
        $this->actingAs($collector)->post(route('messages.store'), [])->assertForbidden();
        $this->actingAs($collector)->getJson(route('messages.quick'))->assertForbidden();
        $this->actingAs($collector)->getJson(route('messages.recipients', ['search' => 'Ali']))->assertForbidden();
    }

    public function test_a_user_who_may_only_view_messages_sees_the_list_but_cannot_write_one(): void
    {
        $this->seed(PermissionSeeder::class);
        $collector = User::factory()->collector()->create();
        $collector->permissions()->attach(Permission::where('key', PermissionKey::ViewMessages->value)->firstOrFail());

        $this->actingAs($collector)->get(route('messages.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Messages/Index')->where('canSend', false));
        $this->actingAs($collector)->get(route('messages.create'))->assertForbidden();
    }

    public function test_the_weekly_reading_preview_lists_only_subscriptions_with_an_approved_reading_that_week(): void
    {
        $this->travelTo('2026-09-24 10:00:00');
        $branchAdmin = User::factory()->branchAdmin()->create();
        $approved = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id, 'full_name' => 'Approved Reading']);
        $pending = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id]);
        Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id]);
        MeterReading::factory()->approved()->for($approved)->create([
            'week_start' => $this->weekStart(),
            'previous_reading' => 100,
            'current_reading' => 150.5,
            'consumption' => 50.5,
            'unit_price' => 1.75,
            'amount_due' => 75,
        ]);
        MeterReading::factory()->for($pending)->create(['week_start' => $this->weekStart()]);

        $this->actingAs($branchAdmin)
            ->get(route('messages.create', ['kind' => 'weekly_reading', 'week_start' => $this->weekStart(), 'approved_only' => 1]))
            ->assertInertia(fn ($page) => $page->reloadOnly('recipients', fn ($reload) => $reload
                ->has('recipients', 1)
                ->where('recipients.0.id', $approved->id)
                ->where('recipients.0.variables.القراءة_السابقة', '100')
                ->where('recipients.0.variables.القراءة_الحالية', '150.5')
                ->where('recipients.0.variables.الاستهلاك', '50.5')
                ->where('recipients.0.variables.قيمة_القراءة', '75')
                ->where('recipients.0.variables.سعر_الكيلو', '1.75')
                ->where('recipients.0.variables.تاريخ_القراءة', '24/9/2026')
                ->where('recipients.0.variables.يوم_القراءة', 'الخميس')));
    }

    public function test_three_thousand_approved_readings_are_personalized_and_queued_in_one_send(): void
    {
        $this->travelTo('2026-09-24 10:00:00');
        $actor = User::factory()->branchAdmin()->create();
        $sample = Subscription::factory()->create(['branch_id' => $actor->branch_id]);
        $subscriptions = Subscription::factory()->count(3000)->has(
            MeterReading::factory()->approved()->state([
                'week_start' => '2026-09-18', 'week_end' => '2026-09-24', 'recorded_by' => $actor->id,
                'previous_reading' => 100, 'current_reading' => 125, 'consumption' => 25, 'unit_price' => 1.75,
            ]), 'meterReadings',
        )->create([
            'branch_id' => $actor->branch_id, 'meter_box_id' => $sample->meter_box_id,
            'tariff_id' => $sample->tariff_id, 'circuit_breaker_id' => $sample->circuit_breaker_id,
            'registered_by' => $actor->id, 'phone' => '0599000001',
        ]);
        Queue::fake([SendSubscriptionMessage::class]);

        $this->actingAs($actor)->postJson(route('messages.store'), [
            'kind' => 'weekly_reading', 'channel' => 'sms', 'approved_only' => true,
            'week_start' => '2026-09-18', 'subscription_ids' => $subscriptions->modelKeys(),
            'body' => '{الاسم}: {القراءة_السابقة} → {القراءة_الحالية}، الفرق {الاستهلاك}، سعر الكيلو {سعر_الكيلو}، {يوم_القراءة} {تاريخ_القراءة}',
        ])->assertRedirect();

        $this->assertDatabaseCount('subscription_messages', 3000);
        $this->assertDatabaseHas('subscription_messages', [
            'subscription_id' => $subscriptions->last()->id,
            'body' => $subscriptions->last()->displayName().': 100 → 125، الفرق 25، سعر الكيلو 1.75، الخميس 24/9/2026',
        ]);
        Queue::assertPushed(SendSubscriptionMessage::class, 3000);
    }

    public function test_the_balance_reminder_preview_lists_only_subscriptions_owing_more_than_the_minimum(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $owing = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id]);
        $owingLittle = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id]);
        Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id]);
        SubscriptionTransaction::factory()->for($owing)->create(['amount' => '120.50']);
        SubscriptionTransaction::factory()->for($owingLittle)->create(['amount' => '10.00']);

        $this->actingAs($branchAdmin)
            ->get(route('messages.create', ['kind' => 'balance_reminder', 'min_balance' => 20]))
            ->assertInertia(fn ($page) => $page->reloadOnly('recipients', fn ($reload) => $reload
                ->has('recipients', 1)
                ->where('recipients.0.id', $owing->id)
                ->where('recipients.0.variables.الرصيد', '120.50')));
    }

    public function test_sending_an_sms_fills_in_each_subscriptions_details_and_queues_one_message_each(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $first = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id, 'full_name' => 'Ali Hasan', 'phone' => '0599000001']);
        $second = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id, 'full_name' => 'Sara Omar', 'phone' => '0599000002']);
        Queue::fake([SendSubscriptionMessage::class]);

        $response = $this->actingAs($branchAdmin)->post(route('messages.store'), [
            'kind' => MessageKind::Custom->value,
            'channel' => MessageChannel::Sms->value,
            'body' => 'مرحبا {الاسم}، اشتراكك {رقم_الاشتراك}.',
            'subscription_ids' => [$first->id, $second->id],
        ]);

        $batch = MessageBatch::sole();
        $response->assertRedirect(route('messages.show', $batch))->assertSessionHas('status', 'messages-queued');
        $this->assertSame($branchAdmin->branch_id, $batch->branch_id);
        $this->assertDatabaseHas('subscription_messages', [
            'subscription_id' => $first->id,
            'phone' => '0599000001',
            'body' => 'مرحبا Ali Hasan، اشتراكك '.$first->fresh()->account_number.'.',
            'status' => MessageStatus::Pending->value,
        ]);
        Queue::assertPushed(SendSubscriptionMessage::class, 2);
    }

    public function test_subscriptions_without_a_phone_are_left_out_of_a_send(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $withPhone = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id]);
        $withoutPhone = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id, 'phone' => null]);
        Queue::fake([SendSubscriptionMessage::class]);

        $this->actingAs($branchAdmin)->post(route('messages.store'), [
            'kind' => MessageKind::Custom->value,
            'channel' => MessageChannel::Sms->value,
            'body' => 'تحديث',
            'subscription_ids' => [$withPhone->id, $withoutPhone->id],
        ]);

        $this->assertDatabaseHas('subscription_messages', ['subscription_id' => $withPhone->id]);
        $this->assertDatabaseMissing('subscription_messages', ['subscription_id' => $withoutPhone->id]);
    }

    public function test_a_branch_admin_cannot_message_another_branchs_subscriptions(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $foreign = Subscription::factory()->create();
        Queue::fake([SendSubscriptionMessage::class]);

        $this->actingAs($branchAdmin)->post(route('messages.store'), [
            'kind' => MessageKind::Custom->value,
            'channel' => MessageChannel::Sms->value,
            'body' => 'تحديث',
            'subscription_ids' => [$foreign->id],
        ])->assertSessionHasErrors(['subscription_ids' => 'لا يوجد بين المختارين من لديه رقم هاتف ويطابق شروط الرسالة.']);

        $this->assertDatabaseCount('message_batches', 0);
        Queue::assertNothingPushed();
    }

    public function test_a_send_needs_a_message_and_recipients(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();

        $this->actingAs($branchAdmin)
            ->post(route('messages.store'), ['kind' => MessageKind::Custom->value, 'channel' => MessageChannel::Sms->value])
            ->assertSessionHasErrors([
                'body' => 'اكتب نص الرسالة.',
                'subscription_ids' => 'اختر مستلمًا واحدًا على الأقل.',
            ]);
    }

    public function test_the_quick_message_window_gets_the_choices_and_the_latest_ended_week(): void
    {
        $this->freezeTime();
        $branchAdmin = User::factory()->branchAdmin()->create();

        $this->actingAs($branchAdmin)->getJson(route('messages.quick'))
            ->assertOk()
            ->assertJsonPath('weekStart', $this->weekStart())
            ->assertJsonPath('weekBranchId', $branchAdmin->branch_id)
            ->assertJsonPath('maxRecipients', 5000)
            ->assertJsonCount(3, 'kinds');
    }

    public function test_the_quick_message_search_finds_the_branchs_subscriptions_of_any_status(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $disconnected = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id, 'full_name' => 'Ali Hasan', 'status' => SubscriptionStatus::Disconnected]);
        Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id, 'full_name' => 'Sara Omar']);
        Subscription::factory()->create(['full_name' => 'Ali Foreign']);

        $this->actingAs($branchAdmin)
            ->getJson(route('messages.recipients', ['kind' => 'custom', 'status' => '', 'search' => 'Ali']))
            ->assertOk()
            ->assertJsonCount(1, 'recipients')
            ->assertJsonPath('recipients.0.id', $disconnected->id);
    }

    public function test_the_quick_message_lists_only_the_picked_subscriptions_the_kind_reaches(): void
    {
        $this->freezeTime();
        $branchAdmin = User::factory()->branchAdmin()->create();
        $withReading = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id]);
        $withoutReading = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id]);
        Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id]);
        MeterReading::factory()->approved()->for($withReading)->create(['week_start' => $this->weekStart(), 'consumption' => 12]);

        $this->actingAs($branchAdmin)
            ->getJson(route('messages.recipients', [
                'kind' => 'weekly_reading',
                'status' => '',
                'week_start' => $this->weekStart(),
                'approved_only' => 1,
                'subscription_ids' => [$withReading->id, $withoutReading->id],
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'recipients')
            ->assertJsonPath('recipients.0.id', $withReading->id)
            ->assertJsonPath('recipients.0.variables.الاستهلاك', '12');
    }

    public function test_the_quick_message_lists_nobody_without_a_search_or_picked_subscriptions(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id]);

        $this->actingAs($branchAdmin)
            ->getJson(route('messages.recipients', ['kind' => 'custom', 'status' => '']))
            ->assertOk()
            ->assertExactJson(['recipients' => []]);
    }

    public function test_an_sms_from_the_quick_message_window_stays_on_the_page(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id, 'phone' => '0599000001']);
        Queue::fake([SendSubscriptionMessage::class]);

        $this->actingAs($branchAdmin)
            ->from(route('subscriptions.index'))
            ->post(route('messages.store'), [
                'kind' => MessageKind::Custom->value,
                'channel' => MessageChannel::Sms->value,
                'body' => 'تحديث',
                'status' => '',
                'subscription_ids' => [$subscription->id],
                'stay_on_page' => true,
            ])
            ->assertRedirect(route('subscriptions.index'))
            ->assertSessionHas('status', 'messages-queued');

        $this->assertDatabaseHas('subscription_messages', ['subscription_id' => $subscription->id]);
        Queue::assertPushed(SendSubscriptionMessage::class, 1);
    }

    public function test_a_whatsapp_send_from_the_quick_message_window_opens_its_page(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id, 'phone' => '0599000001']);

        $response = $this->actingAs($branchAdmin)
            ->from(route('subscriptions.index'))
            ->post(route('messages.store'), [
                'kind' => MessageKind::Custom->value,
                'channel' => MessageChannel::WhatsApp->value,
                'body' => 'تحديث',
                'status' => '',
                'subscription_ids' => [$subscription->id],
                'stay_on_page' => true,
            ]);

        $response->assertRedirect(route('messages.show', MessageBatch::sole()))->assertSessionHas('status', 'messages-ready');
    }

    public function test_the_sms_gateway_marks_a_message_sent_when_the_provider_accepts_it(): void
    {
        config(['services.sms.driver' => 'http', 'services.sms.http.url' => 'https://sms.example.test/send', 'services.sms.http.success_match' => 'OK']);
        Http::preventStrayRequests();
        Http::fake(['https://sms.example.test/send' => Http::response('OK:123')]);
        $message = SubscriptionMessage::factory()->create(['phone' => '0599123456', 'body' => 'مرحبا']);

        SendSubscriptionMessage::dispatchSync($message);

        $this->assertSame(MessageStatus::Sent, $message->fresh()->status);
        Http::assertSent(fn ($request) => $request['to'] === '970599123456' && $request['message'] === 'مرحبا');
    }

    public function test_the_sms_gateway_marks_a_message_failed_with_the_reason_when_the_provider_refuses_it(): void
    {
        config(['services.sms.driver' => 'http', 'services.sms.http.url' => 'https://sms.example.test/send']);
        Http::preventStrayRequests();
        Http::fake(['https://sms.example.test/send' => Http::response('Insufficient balance', 402)]);
        $message = SubscriptionMessage::factory()->create();

        SendSubscriptionMessage::dispatchSync($message);

        $message->refresh();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertStringContainsString('Insufficient balance', $message->error);
    }

    public function test_a_queued_message_the_provider_refuses_is_marked_failed_without_another_try(): void
    {
        config(['services.sms.driver' => 'http', 'services.sms.http.url' => 'https://sms.example.test/send']);
        Http::preventStrayRequests();
        Http::fake(['https://sms.example.test/send' => Http::response('Insufficient balance', 402)]);
        $message = SubscriptionMessage::factory()->create();
        $job = (new SendSubscriptionMessage($message))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        $job->assertNotReleased();
        $this->assertSame(MessageStatus::Failed, $message->fresh()->status);
    }

    public function test_a_queued_message_waits_and_is_tried_again_when_the_provider_is_down(): void
    {
        config(['services.sms.driver' => 'http', 'services.sms.http.url' => 'https://sms.example.test/send']);
        Http::preventStrayRequests();
        Http::fake(['https://sms.example.test/send' => Http::response('Service unavailable', 503)]);
        $message = SubscriptionMessage::factory()->create();
        $job = (new SendSubscriptionMessage($message))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        $job->assertReleased(delay: 60);
        $this->assertSame(MessageStatus::Pending, $message->fresh()->status);
    }

    public function test_a_queued_message_is_tried_again_later_when_the_provider_cannot_be_reached(): void
    {
        config(['services.sms.driver' => 'http', 'services.sms.http.url' => 'https://sms.example.test/send']);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));
        $message = SubscriptionMessage::factory()->create();
        $job = (new SendSubscriptionMessage($message))->withFakeQueueInteractions();
        $job->job->attempts = 2;

        app()->call([$job, 'handle']);

        $job->assertReleased(delay: 300);
        $this->assertSame(MessageStatus::Pending, $message->fresh()->status);
    }

    public function test_a_queued_message_is_marked_failed_with_the_reason_when_its_last_try_fails(): void
    {
        config(['services.sms.driver' => 'http', 'services.sms.http.url' => 'https://sms.example.test/send']);
        Http::preventStrayRequests();
        Http::fake(['https://sms.example.test/send' => Http::response('Service unavailable', 503)]);
        $message = SubscriptionMessage::factory()->create();
        $job = (new SendSubscriptionMessage($message))->withFakeQueueInteractions();
        $job->job->attempts = 3;

        app()->call([$job, 'handle']);

        $job->assertNotReleased();
        $message->refresh();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertStringContainsString('503', $message->error);
    }

    public function test_retrying_a_send_queues_its_failed_messages_again(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $batch = MessageBatch::factory()->create(['branch_id' => $branchAdmin->branch_id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id]);
        $failed = SubscriptionMessage::factory()->failed()->for($batch, 'batch')->for($subscription)->create();
        SubscriptionMessage::factory()->for($batch, 'batch')->for($subscription)->create(['status' => MessageStatus::Sent]);
        Queue::fake([SendSubscriptionMessage::class]);

        $this->actingAs($branchAdmin)->post(route('messages.retry', $batch))->assertSessionHas('status', 'messages-retried');

        $this->assertSame(MessageStatus::Pending, $failed->fresh()->status);
        Queue::assertPushed(SendSubscriptionMessage::class, 1);
        Queue::assertPushed(SendSubscriptionMessage::class, fn (SendSubscriptionMessage $job) => $job->message->is($failed));
    }

    public function test_opening_a_whatsapp_message_marks_it_sent_by_the_staff_member(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $batch = MessageBatch::factory()->whatsApp()->create(['branch_id' => $branchAdmin->branch_id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id]);
        $message = SubscriptionMessage::factory()->for($batch, 'batch')->for($subscription)->create();

        $this->actingAs($branchAdmin)->put(route('messages.sent', [$batch, $message]))->assertRedirect();

        $message->refresh();
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame($branchAdmin->id, $message->sent_by);
    }

    public function test_a_branch_admin_cannot_see_or_change_another_branchs_send(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $foreignBatch = MessageBatch::factory()->whatsApp()->create();
        $message = SubscriptionMessage::factory()->for($foreignBatch, 'batch')->create();

        $this->actingAs($branchAdmin)->get(route('messages.show', $foreignBatch))->assertForbidden();
        $this->actingAs($branchAdmin)->put(route('messages.sent', [$foreignBatch, $message]))->assertForbidden();

        $this->assertSame(MessageStatus::Pending, $message->fresh()->status);
    }

    public function test_the_whatsapp_link_carries_the_international_number_and_the_text(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $batch = MessageBatch::factory()->whatsApp()->create(['branch_id' => $branchAdmin->branch_id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id]);
        SubscriptionMessage::factory()->for($batch, 'batch')->for($subscription)->create(['phone' => '0599123456', 'body' => 'مرحبا علي']);

        $this->actingAs($branchAdmin)->get(route('messages.show', $batch))
            ->assertInertia(fn ($page) => $page->where('messages.data.0.whatsAppLink', 'https://wa.me/970599123456?text='.rawurlencode('مرحبا علي')));
    }
}
