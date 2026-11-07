<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->subscription = Subscription::factory()->create(['branch_id' => $this->branch->id]);
    }

    public function test_a_collector_who_may_take_only_bank_transfers_is_offered_and_allowed_only_those(): void
    {
        $collector = $this->staffAllowedTo(PermissionKey::ViewSubscriptions, PermissionKey::RecordCollections, PermissionKey::RecordTransferPayments);

        $this->actingAs($collector)->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('canRecordPayment', true)
                ->where('payableMethods', [['value' => 'bank_transfer', 'label' => __('Bank Transfer')]])
                ->has('paymentMethods', 2));

        $this->post(route('subscriptions.payments.store', $this->subscription), ['amount' => '25', 'currency' => 'ILS', 'payment_method' => 'cash'])
            ->assertSessionHasErrors(['payment_method' => 'لا تملك صلاحية الدفع نقدًا؛ اطلبها من مدير الفرع.']);

        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_a_collector_who_may_take_only_cash_records_cash_and_cannot_split_a_transfer(): void
    {
        $collector = $this->staffAllowedTo(PermissionKey::RecordCollections, PermissionKey::RecordCashPayments);

        $this->actingAs($collector)
            ->post(route('subscriptions.payments.store', $this->subscription), ['amount' => '25', 'currency' => 'ILS', 'payment_method' => 'cash'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('subscription_transactions', ['subscription_id' => $this->subscription->id, 'payment_method' => 'cash']);

        $this->get(route('payments.index'))->assertInertia(fn ($page) => $page
            ->where('canSplitPayments', false)
            ->where('paymentMethods', [['value' => 'cash', 'label' => __('Cash')]]));
        $this->post(route('payments.split.store'), [])->assertForbidden();
    }

    public function test_adding_payments_without_any_way_to_be_paid_takes_no_payment(): void
    {
        $collector = $this->staffAllowedTo(PermissionKey::RecordCollections);

        $this->actingAs($collector)->get(route('payments.index'))->assertForbidden();
        $this->post(route('subscriptions.payments.store', $this->subscription), ['amount' => '25', 'currency' => 'ILS', 'payment_method' => 'cash'])
            ->assertForbidden();
    }

    public function test_charges_discounts_and_clearings_each_take_their_own_permission(): void
    {
        $charger = $this->staffAllowedTo(PermissionKey::ViewSubscriptions, PermissionKey::AddCharges);

        $this->actingAs($charger)->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('canAddCharge', true)
                ->where('canAddDiscount', false)
                ->where('canAddClearing', false));

        // Allowed: the request reaches validation (it is empty) instead of being refused.
        $this->post(route('subscriptions.charges.store', $this->subscription), [])->assertSessionHasErrors();
        $this->post(route('subscriptions.discounts.store', $this->subscription), [])->assertForbidden();
        $this->put(route('subscriptions.standing-discount.update', $this->subscription), [])->assertForbidden();
        $this->delete(route('subscriptions.standing-discount.destroy', $this->subscription))->assertForbidden();
        $this->post(route('subscriptions.clearings.store', $this->subscription), [])->assertForbidden();

        $clearer = $this->staffAllowedTo(PermissionKey::AddClearings);
        $this->actingAs($clearer)->post(route('subscriptions.clearings.store', $this->subscription), [])->assertSessionHasErrors();
        $this->post(route('subscriptions.charges.store', $this->subscription), [])->assertForbidden();
    }

    public function test_the_old_permissions_hand_their_holders_the_new_ones_they_covered(): void
    {
        $collector = User::factory()->collector()->create(['branch_id' => $this->branch->id]);
        $accountant = User::factory()->accountant()->create(['branch_id' => $this->branch->id]);
        $adjustId = DB::table('permissions')->insertGetId(['key' => 'collections.adjust', 'label' => 'Add Charges and Discounts', 'created_at' => now(), 'updated_at' => now()]);
        $collector->permissions()->sync(Permission::idsFor([PermissionKey::RecordCollections]));
        $accountant->permissions()->sync([$adjustId]);
        DB::table('permissions')->whereIn('key', ['collections.record_cash', 'collections.record_transfer', 'collections.charge', 'collections.discount', 'collections.clearing'])->delete();

        (require database_path('migrations/2026_10_16_204029_split_payment_and_balance_permissions.php'))->up();

        $this->assertEqualsCanonicalizing(
            ['collections.record', 'collections.record_cash', 'collections.record_transfer'],
            $collector->fresh()->permissions()->pluck('key')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['collections.charge', 'collections.discount', 'collections.clearing'],
            $accountant->fresh()->permissions()->pluck('key')->all(),
        );
        $this->assertDatabaseMissing('permissions', ['key' => 'collections.adjust']);
    }

    private function staffAllowedTo(PermissionKey ...$permissions): User
    {
        $user = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]);
        $user->permissions()->sync(Permission::idsFor($permissions));

        return $user;
    }
}
