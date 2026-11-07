<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Jobs\SendSubscriptionMessage;
use App\Models\MobileAccessToken;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MobileCollectorWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_collector_searches_records_once_reviews_history_and_messages_within_the_branch(): void
    {
        $this->travelTo('2026-10-21 09:00:00');
        Http::preventStrayRequests();
        Queue::fake([SendSubscriptionMessage::class]);
        config(['services.sms.driver' => 'http']);
        $collector = User::factory()->create();
        $collector->permissions()->sync(Permission::idsFor([PermissionKey::RecordCollections, PermissionKey::RecordTransferPayments, PermissionKey::ViewSubscriptions, PermissionKey::SendMessages, PermissionKey::ViewMessages]));
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id, 'full_name' => 'عميل سير التحصيل', 'phone' => '0599000111']);
        $foreign = Subscription::factory()->create(['full_name' => 'عميل فرع آخر']);
        SubscriptionTransaction::factory()->create(['subscription_id' => $subscription->id, 'amount' => '100.00']);
        $token = $this->postJson(route('mobile.login'), ['username' => $collector->username, 'password' => 'password'])->assertOk()->assertJsonPath('user.payment_methods', ['bank_transfer'])->json('token');
        $this->withHeader('Authorization', 'Bearer '.$token);
        $this->getJson(route('mobile.collections.subscriptions', ['search' => 'عميل']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $subscription->id);
        $this->getJson(route('mobile.collections.subscriptions', ['search' => $subscription->account_number]))->assertOk()->assertJsonPath('data.0.id', $subscription->id);
        $this->getJson(route('mobile.collections.subscriptions.show', $foreign))->assertNotFound();
        $this->getJson(route('mobile.collections.subscriptions.show', $subscription))->assertOk()->assertJsonPath('permissions.payment_methods', ['bank_transfer'])->assertJsonPath('permissions.view_history', true)->assertJsonPath('messaging.can_send', true);
        $payment = ['mobile_operation_id' => (string) Str::uuid(), 'subscription_id' => $subscription->id, 'amount' => '25.00', 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'صاحب الحوالة', 'reference_number' => 'MOBILE-WORKFLOW-25', 'collector_confirmed' => true];
        $this->postJson(route('mobile.collections.store'), [...$payment, 'payment_method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertSame(100.0, $subscription->fresh()->balance());
        $id = $this->postJson(route('mobile.collections.store'), $payment)->assertCreated()->assertJsonPath('balance_after', '75.00')->json('id');
        $this->postJson(route('mobile.collections.store'), $payment)->assertCreated()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('subscription_transactions', 2);
        $this->assertSame(75.0, $subscription->fresh()->balance());
        $this->getJson(route('mobile.collections.index'))->assertOk()->assertJsonPath('total', 25)->assertJsonPath('bank_transfer_total', 25)->assertJsonMissingPath('cash_total')->assertJsonCount(1, 'data');
        $this->getJson(route('mobile.collections.subscriptions.transactions', $subscription))->assertOk()->assertJsonPath('balance', '75.00')->assertJsonPath('data.0.reference_number', 'MOBILE-WORKFLOW-25')->assertJsonPath('data.0.amount', '25.00');
        $message = ['mobile_operation_id' => (string) Str::uuid(), 'body' => 'تم تسجيل دفعتك، شكرًا لك.'];
        $url = route('mobile.collections.subscriptions.messages.store', $subscription);
        $messageId = $this->postJson($url, $message)->assertCreated()->assertJsonPath('status', 'pending')->json('id');
        $this->postJson($url, $message)->assertCreated()->assertJsonPath('id', $messageId);
        $this->getJson(route('mobile.collections.subscriptions.messages.index', $subscription))->assertOk()->assertJsonCount(1, 'messages.data')->assertJsonPath('messages.data.0.kind', 'custom');
        $this->assertSame(75.0, $subscription->fresh()->balance());
        Queue::assertPushedOnce(SendSubscriptionMessage::class);
        Http::assertNothingSent();
    }

    #[TestWith(['cash'])]
    #[TestWith(['bank_transfer'])]
    #[TestWith(['both'])]
    #[TestWith(['none'])]
    public function test_summary_follows_permissions_and_preserves_historical_collections(string $allowed): void
    {
        $this->travelTo('2026-10-21 09:00:00');
        $collector = User::factory()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id]);
        foreach (['cash' => 20, 'bank_transfer' => 30] as $method => $amount) {
            SubscriptionTransaction::factory()->create(['subscription_id' => $subscription->id, 'recorded_by' => $collector->id, 'type' => SubscriptionTransaction::TYPE_PAYMENT, 'source_key' => 'payment:summary:'.$method, 'payment_method' => $method, 'amount' => -$amount]);
        }
        $keys = [PermissionKey::RecordCollections];
        if (in_array($allowed, ['cash', 'both'], true)) {
            $keys[] = PermissionKey::RecordCashPayments;
        }
        if (in_array($allowed, ['bank_transfer', 'both'], true)) {
            $keys[] = PermissionKey::RecordTransferPayments;
        }
        $collector->permissions()->sync(Permission::idsFor($keys));
        $response = $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector))->getJson(route('mobile.collections.index'))->assertOk()->assertJsonPath('total', 50)->assertJsonCount(2, 'data');
        in_array($allowed, ['cash', 'both'], true) ? $response->assertJsonPath('cash_total', 20) : $response->assertJsonMissingPath('cash_total');
        in_array($allowed, ['bank_transfer', 'both'], true) ? $response->assertJsonPath('bank_transfer_total', 30) : $response->assertJsonMissingPath('bank_transfer_total');
        $this->assertSame(-50.0, $subscription->fresh()->balance());
    }

    public function test_revoking_collection_blocks_new_payments_without_breaking_receipt_recovery(): void
    {
        $this->travelTo('2026-10-21 09:00:00');
        $collector = User::factory()->withPermissions([PermissionKey::RecordCollections, PermissionKey::RecordTransferPayments])->create();
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id]);
        $payment = ['mobile_operation_id' => (string) Str::uuid(), 'subscription_id' => $subscription->id, 'amount' => 25, 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'مرسل', 'reference_number' => 'REVOKED-25', 'collector_confirmed' => true, 'confirm_overpayment' => true];
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));
        $id = $this->postJson(route('mobile.collections.store'), $payment)->assertCreated()->json('id');
        $collector->permissions()->sync([]);
        $this->getJson(route('mobile.me'))->assertOk()->assertJsonPath('user.can_record_collections', false)->assertJsonPath('user.payment_methods', []);
        $this->postJson(route('mobile.collections.store'), [...$payment, 'mobile_operation_id' => (string) Str::uuid()])->assertForbidden();
        $this->postJson(route('mobile.collections.store'), $payment)->assertCreated()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('subscription_transactions', 1);
        $this->assertSame(-25.0, $subscription->fresh()->balance());
    }
}
