<?php

namespace Tests\Feature\Users;

use App\Enums\PermissionKey;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\MobileAccessToken;
use App\Models\SplitPayment;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StarterPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_collector_can_take_a_payment_in_the_app_without_anyone_ticking_anything(): void
    {
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));
        $this->getJson(route('mobile.collections.subscriptions'))->assertOk();
        $this->postJson(route('mobile.collections.store'), [
            'mobile_operation_id' => Str::uuid()->toString(),
            'subscription_id' => $subscription->id,
            'amount' => '25.00',
            'currency' => 'ILS',
            'payment_method' => 'cash',
            'collector_confirmed' => true,
        ])->assertCreated()->assertJsonPath('status', 'recorded');
    }

    public function test_a_new_collector_holds_only_the_permissions_to_take_payments(): void
    {
        $collector = User::factory()->collector()->create();

        $this->assertEqualsCanonicalizing(
            [PermissionKey::RecordCollections->value, PermissionKey::RecordCashPayments->value, PermissionKey::RecordTransferPayments->value],
            $collector->permissions()->pluck('key')->all(),
        );
        $this->assertFalse($collector->hasPermission(PermissionKey::ViewCollections));
        $this->actingAs($collector)->get(route('ledger.index'))->assertForbidden();
    }

    public function test_a_new_financial_auditor_cannot_open_unrelated_reports_or_split_payments(): void
    {
        $auditor = User::factory()->financialAuditor()->create();
        $split = SplitPayment::factory()->create(['total_amount' => 100]);
        SubscriptionTransaction::factory()->count(2)->create([
            'split_payment_id' => $split->id,
            'type' => SubscriptionTransaction::TYPE_PAYMENT,
            'amount' => '-50.00',
            'payment_method' => 'bank_transfer',
        ]);

        $this->actingAs($auditor)->get(route('reports.index'))->assertForbidden();
        $this->assertFalse($auditor->hasPermission(PermissionKey::ViewAllFinancialReports));
        $this->get(route('split-payments.show', $split))->assertNotFound();
        $this->get(route('dashboard'))->assertRedirect(route('payments.cash-boxes', ['place' => 'closed']));
        $this->get(route('profile.edit'))->assertOk();
    }

    public function test_a_branch_admin_cannot_create_a_financial_auditor(): void
    {
        $branch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);

        $this->actingAs($branchAdmin)->post(route('users.store'), [
            'name' => 'New Auditor', 'username' => 'new.auditor', 'password' => 'Tulip2026ab', 'password_confirmation' => 'Tulip2026ab',
            'role' => UserRole::FinancialAuditor->value,
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['username' => 'new.auditor']);
    }
}
