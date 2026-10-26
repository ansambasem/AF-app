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
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MobileAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['charge', 'penalty', 'collections.charge', '25.00', '125.00'])]
    #[TestWith(['charge', 'subscription_fee', 'collections.charge', '25.00', '125.00'])]
    #[TestWith(['charge', 'disconnection_fee', 'collections.charge', '25.00', '125.00'])]
    #[TestWith(['charge', 'minimum_charge', 'collections.charge', '25.00', '125.00'])]
    #[TestWith(['discount', null, 'collections.discount', '-25.00', '75.00'])]
    #[TestWith(['clearing', null, 'collections.clearing', '-25.00', '75.00'])]
    public function test_permitted_adjustment_is_recorded_once_and_permissions_can_be_revoked(string $action, ?string $type, string $permission, string $amount, string $balance): void
    {
        $collector = User::factory()->withPermissions([PermissionKey::from($permission)])->create();
        $collector->permissions()->sync(Permission::idsFor([PermissionKey::from($permission)]));
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id, 'minimum_charge' => 25]);
        SubscriptionTransaction::factory()->create(['subscription_id' => $subscription->id, 'amount' => 100]);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));
        $data = ['mobile_operation_id' => (string) Str::uuid(), 'action' => $action, 'type' => $type, 'amount' => 25, 'method' => 'shekel', 'value' => 25, 'notes' => 'خدمة موثّقة'];
        $url = route('mobile.collections.subscriptions.adjustments', $subscription);

        $this->getJson(route('mobile.me'))->assertJsonPath('user.can_adjust_balances', true)->assertJsonPath('user.can_add_clearings', $action === 'clearing')->assertJsonPath('user.can_record_collections', false);
        $this->getJson(route('mobile.collections.subscriptions'))->assertJsonPath('data.0.id', $subscription->id);
        $this->getJson(route('mobile.collections.subscriptions.show', $subscription))
            ->assertJsonPath('permissions.add_charge', $action === 'charge')
            ->assertJsonPath('permissions.add_discount', $action === 'discount')
            ->assertJsonPath('permissions.add_clearing', $action === 'clearing')
            ->assertJsonPath('permissions.record_payment', false);
        $id = $this->postJson($url, $data)->assertCreated()->assertJsonPath('amount', $amount)->assertJsonPath('balance_after', $balance)->json('id');
        $collector->permissions()->sync([]);
        $this->postJson($url, $data)->assertCreated()->assertJsonPath('id', $id);
        $this->postJson($url, [...$data, 'mobile_operation_id' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('subscription_transactions', 2);
        $this->assertDatabaseHas('subscription_transactions', ['id' => $id, 'subscription_id' => $subscription->id, 'recorded_by' => $collector->id, 'amount' => $amount, 'notes' => 'خدمة موثّقة']);
    }

    #[TestWith(['charge'])]
    #[TestWith(['discount'])]
    #[TestWith(['clearing'])]
    public function test_payment_permission_does_not_grant_adjustments(string $action): void
    {
        $collector = User::factory()->withPermissions([PermissionKey::RecordCollections, PermissionKey::RecordCashPayments])->create();
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id]);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector))
            ->postJson(route('mobile.collections.subscriptions.adjustments', $subscription), ['action' => $action, 'mobile_operation_id' => (string) Str::uuid()])->assertForbidden();

        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_adjustments_require_authentication_and_hide_foreign_subscriptions(): void
    {
        $collector = User::factory()->withPermissions([PermissionKey::AddCharges])->create();
        $foreign = Subscription::factory()->create();
        $url = route('mobile.collections.subscriptions.adjustments', $foreign);

        $this->postJson($url)->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector))
            ->postJson($url, ['action' => 'charge'])->assertNotFound();

        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_operation_cannot_be_reused_for_another_subscriber_action_or_collector(): void
    {
        $collector = User::factory()->withPermissions([PermissionKey::AddCharges, PermissionKey::AddClearings])->create();
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id]);
        $other = Subscription::factory()->create(['branch_id' => $collector->branch_id]);
        $data = ['mobile_operation_id' => (string) Str::uuid(), 'action' => 'charge', 'type' => 'subscription_fee', 'amount' => 25];
        $url = route('mobile.collections.subscriptions.adjustments', $subscription);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));
        $this->postJson($url, $data)->assertCreated();

        $this->postJson(route('mobile.collections.subscriptions.adjustments', $other), $data)->assertForbidden();
        $this->postJson($url, [...$data, 'action' => 'clearing'])->assertForbidden();
        $another = User::factory()->withPermissions([PermissionKey::AddCharges])->create(['branch_id' => $collector->branch_id]);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($another))->postJson($url, $data)->assertForbidden();
        $this->assertDatabaseCount('subscription_transactions', 1);
    }

    #[TestWith(['charge', ['type' => 'penalty', 'amount' => 25], 'notes'])]
    #[TestWith(['charge', ['type' => 'minimum_charge', 'amount' => 20], 'amount'])]
    #[TestWith(['charge', ['type' => 'unknown', 'amount' => 25], 'type'])]
    #[TestWith(['charge', ['type' => 'subscription_fee', 'amount' => -1], 'amount'])]
    #[TestWith(['discount', ['method' => 'shekel', 'value' => 101], 'value'])]
    #[TestWith(['discount', ['method' => 'percentage', 'value' => 10], 'method'])]
    #[TestWith(['clearing', ['amount' => 10], 'notes'])]
    #[TestWith(['clearing', ['amount' => 101, 'notes' => 'خدمة'], 'confirm_over_balance'])]
    public function test_invalid_adjustments_leave_the_account_unchanged(string $action, array $payload, string $field): void
    {
        $collector = User::factory()->withPermissions([PermissionKey::AddCharges, PermissionKey::AddDiscounts, PermissionKey::AddClearings])->create();
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id, 'minimum_charge' => 25]);
        SubscriptionTransaction::factory()->create(['subscription_id' => $subscription->id, 'amount' => 100]);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector))
            ->postJson(route('mobile.collections.subscriptions.adjustments', $subscription), ['mobile_operation_id' => (string) Str::uuid(), 'action' => $action, ...$payload])
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('subscription_transactions', 1);
        $this->assertSame(100.0, $subscription->fresh()->balance());
    }

    public function test_confirmed_clearing_can_leave_credit_and_has_a_voucher(): void
    {
        $collector = User::factory()->withPermissions([PermissionKey::AddClearings])->create();
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id]);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector))
            ->postJson(route('mobile.collections.subscriptions.adjustments', $subscription), ['mobile_operation_id' => (string) Str::uuid(), 'action' => 'clearing', 'amount' => 25, 'notes' => 'خدمة', 'confirm_over_balance' => true])
            ->assertCreated()->assertJsonPath('balance_after', '-25.00');

        $transaction = $subscription->transactions()->sole();
        $this->assertNotNull($transaction->voucher_number);
        $this->assertSame('-25.00', $transaction->amount);
    }
}
