<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\MobileAccessToken;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileSplitPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function subscriber(User $actor): Subscription
    {
        $subscription = Subscription::factory()->create(['branch_id' => $actor->branch_id]);
        SubscriptionTransaction::factory()->for($subscription)->create(['amount' => '100.00']);

        return $subscription;
    }

    private function payload(Subscription $first, Subscription $second): array
    {
        return ['mobile_operation_id' => (string) Str::uuid(), 'collector_confirmed' => true,
            'total_amount' => '100', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'محمد', 'reference_number' => 'SPLIT-MOBILE-1',
            'parts' => [['subscription_id' => $first->id, 'amount' => '40'], ['subscription_id' => $second->id, 'amount' => '60']]];
    }

    public function test_guest_cannot_record_a_split(): void
    {
        $this->postJson(route('mobile.split-payments.store'), [])->assertUnauthorized();
    }

    public function test_a_mobile_transfer_is_recorded_atomically_and_a_retry_returns_the_same_group(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $first = $this->subscriber($actor);
        $second = $this->subscriber($actor);
        $payload = $this->payload($first, $second);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($actor));

        $response = $this->postJson(route('mobile.split-payments.store'), $payload)
            ->assertCreated()->assertJsonCount(2, 'parts')->assertJsonPath('parts.0.balance_after', '60.00')->assertJsonPath('parts.1.balance_after', '40.00');
        $this->postJson(route('mobile.split-payments.store'), [...$payload, 'total_amount' => '999'])
            ->assertCreated()->assertJsonPath('id', $response->json('id'))->assertJsonPath('total_amount', '100.00')
            ->assertJsonPath('reference_number', 'SPLIT-MOBILE-1')->assertJsonPath('bank_name', 'بنك فلسطين')->assertJsonPath('sender_name', 'محمد');

        $this->assertDatabaseCount('split_payments', 1);
        $this->assertSame(2, SubscriptionTransaction::where('type', SubscriptionTransaction::TYPE_PAYMENT)->count());
        $this->assertDatabaseHas('subscription_transactions', ['mobile_operation_id' => $payload['mobile_operation_id'], 'split_payment_id' => $response->json('id')]);
    }

    public function test_cash_permission_does_not_allow_split_transfers(): void
    {
        $actor = User::factory()->collector()->create();
        $actor->permissions()->sync(Permission::idsFor([PermissionKey::RecordCollections, PermissionKey::RecordCashPayments]));
        $first = $this->subscriber($actor);
        $second = $this->subscriber($actor);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($actor));

        $this->getJson(route('mobile.me'))->assertJsonPath('user.can_record_split_payments', false);
        $this->postJson(route('mobile.split-payments.store'), $this->payload($first, $second))->assertForbidden();

        $this->assertDatabaseCount('split_payments', 0);
        $this->assertSame(0, SubscriptionTransaction::where('type', SubscriptionTransaction::TYPE_PAYMENT)->count());
    }

    public function test_a_foreign_subscription_cannot_leave_a_partial_transfer(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $first = $this->subscriber($actor);
        $foreign = Subscription::factory()->create();
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($actor));

        $this->postJson(route('mobile.split-payments.store'), $this->payload($first, $foreign))->assertJsonValidationErrors('parts.1.subscription_id');

        $this->assertDatabaseCount('split_payments', 0);
        $this->assertSame(100.0, $first->balance());
    }

    public function test_mismatched_allocation_and_missing_confirmation_do_not_record_payments(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $first = $this->subscriber($actor);
        $second = $this->subscriber($actor);
        $payload = $this->payload($first, $second);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($actor));

        $this->postJson(route('mobile.split-payments.store'), [...$payload, 'total_amount' => '101'])->assertJsonValidationErrors('parts');
        $this->postJson(route('mobile.split-payments.store'), [...$payload, 'collector_confirmed' => false])->assertJsonValidationErrors('collector_confirmed');

        $this->assertDatabaseCount('split_payments', 0);
    }

    public function test_another_employee_cannot_reuse_the_mobile_operation_id(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $first = $this->subscriber($actor);
        $second = $this->subscriber($actor);
        $payload = $this->payload($first, $second);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($actor))->postJson(route('mobile.split-payments.store'), $payload)->assertCreated();
        $other = User::factory()->branchAdmin()->create(['branch_id' => $actor->branch_id]);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($other))->postJson(route('mobile.split-payments.store'), $payload)->assertForbidden();

        $this->assertDatabaseCount('split_payments', 1);
    }

    public function test_subscriber_actions_and_statement_expose_the_split_permission(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $subscription = $this->subscriber($actor);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($actor));

        $this->getJson(route('mobile.collections.subscriptions.show', $subscription))->assertJsonPath('permissions.record_split_payment', true);
        $this->actingAs($actor)->get(route('subscriptions.statement', $subscription))->assertInertia(fn ($page) => $page->where('canRecordSplitPayment', true));

        $this->assertDatabaseCount('split_payments', 0);
    }
}
