<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Enums\UserRole;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\TransactionAmendment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class FinancialAuditorAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_assigns_auditing_without_a_system_role_selector(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $employee = User::factory()->collector()->create();

        $this->actingAs($superAdmin)->get(route('users.index'))->assertInertia(fn ($page) => $page
            ->missing('createRoleOptions'));
        $this->get(route('users.edit', $employee))->assertInertia(fn ($page) => $page
            ->missing('roleOptions'));
        $this->put(route('settings.permissions.update'), ['permissions' => [$employee->id => Permission::idsFor([PermissionKey::AuditClosings])]])->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertSame(UserRole::Collector, $employee->role);
        $this->assertTrue($employee->hasPermission(PermissionKey::AuditClosings));
        $this->assertFalse($employee->hasPermission(PermissionKey::UpdateUsers));
    }

    public function test_auditors_can_read_subscriber_history_and_logs_across_branches(): void
    {
        $auditor = User::factory()->financialAuditor()->create();
        $subscription = Subscription::factory()->create();
        $transaction = SubscriptionTransaction::factory()->for($subscription)->create();
        TransactionAmendment::query()->create([
            'transaction_id' => $transaction->id, 'user_id' => $transaction->recorded_by,
            'reason' => 'Reviewed details', 'changes' => ['notes' => ['old' => null, 'new' => 'Reviewed']],
        ]);

        $this->actingAs($auditor)->get(route('subscriptions.index', ['statement' => $subscription->id]))
            ->assertOk()->assertInertia(fn ($page) => $page
            ->where('subscriptions.data.0.id', $subscription->id)
            ->where('statement.subscription.id', $subscription->id)
            ->where('can.viewSubscriptions', true)
            ->where('can.viewLedger', true)
            ->where('can.viewTransactionAudit', true)
            ->where('can.manageSettings', false));
        $this->get(route('subscriptions.statement', $subscription))->assertOk()->assertInertia(fn ($page) => $page
            ->where('subscription.id', $subscription->id)
            ->where('entries.0.id', $transaction->id)
            ->where('canRecordPayment', false)
            ->where('canAdjustBalance', false));
        $this->get(route('subscriptions.personal-details.history', $subscription))->assertOk();
        $this->get(route('ledger.index', ['period' => 'all']))->assertOk()->assertInertia(fn ($page) => $page
            ->has('entries.data', 1));
        $this->get(route('transaction-audit.index', ['period' => 'all']))->assertOk()->assertInertia(fn ($page) => $page
            ->has('events.data', 1));

        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('settings.permissions.edit'))->assertForbidden();
        $this->put(route('subscriptions.update', $subscription), [])->assertForbidden();
    }

    public function test_super_admin_can_grant_and_revoke_the_visible_audit_action_without_changing_the_users_role(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $employee = User::factory()->collector()->create();
        $subscription = Subscription::factory()->create();
        $permissionId = Permission::idsFor([PermissionKey::AuditClosings])[0];

        $this->actingAs($superAdmin)->get(route('settings.permissions.edit', ['selected' => $employee->id]))
            ->assertInertia(fn ($page) => $page->where('permissionGroups', fn ($groups): bool => collect(
                collect($groups)->firstWhere('key', 'financial_audit')['actions'],
            )->contains(fn (array $entry): bool => $entry['action'] === 'audit' && $entry['permission']['id'] === $permissionId)));
        $this->put(route('settings.permissions.update'), ['permissions' => [$employee->id => [$permissionId]]])
            ->assertSessionHasNoErrors();
        $this->assertSame(UserRole::Collector, $employee->fresh()->role);
        $this->actingAs($employee->fresh())->get(route('subscriptions.statement', $subscription))->assertOk();

        $this->actingAs($superAdmin)->put(route('settings.permissions.update'), ['permissions' => [$employee->id => []]])
            ->assertSessionHasNoErrors();
        $this->actingAs($employee->fresh())->get(route('subscriptions.statement', $subscription))->assertForbidden();
        $this->get(route('ledger.index'))->assertForbidden();
    }

    public function test_branch_admin_cannot_assign_the_auditor_role_or_grant_cross_branch_audit_access(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $employee = User::factory()->collector()->for($branchAdmin->branch)->create();
        $permissionId = Permission::idsFor([PermissionKey::AuditClosings])[0];
        $branchAdmin->permissions()->attach($permissionId);

        $this->actingAs($branchAdmin->fresh())->get(route('users.create'))->assertInertia(fn ($page) => $page
            ->missing('roleOptions'));
        $this->put(route('users.update', $employee), [
            'name' => $employee->name, 'username' => $employee->username, 'role' => 'financial_auditor',
        ])->assertSessionHasErrors('role');
        $this->assertSame(UserRole::Collector, $employee->fresh()->role);
        $this->get(route('settings.permissions.edit'))->assertInertia(fn ($page) => $page
            ->where('permissionGroups', fn ($groups): bool => ! collect($groups)->contains('key', 'financial_audit')));
        $this->put(route('settings.permissions.update'), ['permissions' => [$employee->id => [$permissionId]]])
            ->assertSessionHasNoErrors();

        $this->assertFalse($employee->fresh()->hasPermission(PermissionKey::AuditClosings));
    }

    #[TestWith(['collector'])]
    #[TestWith(['data_entry'])]
    #[TestWith(['accountant'])]
    public function test_ordinary_staff_remain_restricted_to_their_branch(string $role): void
    {
        $employee = User::factory()->create(['role' => $role]);
        $employee->permissions()->sync(Permission::idsFor([PermissionKey::ViewSubscriptions]));
        $subscription = Subscription::factory()->create();

        $this->actingAs($employee)->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 0));
        $this->get(route('subscriptions.statement', $subscription))->assertForbidden();
    }

    public function test_audit_read_access_does_not_expand_the_scope_of_bulk_writes(): void
    {
        $employee = User::factory()->branchAdmin()->withPermissions([PermissionKey::AuditClosings])->create();
        $subscription = Subscription::factory()->create(['minimum_charge' => '25.00']);

        $this->assertTrue($employee->can('view', $subscription));
        $this->assertFalse($employee->can('update', $subscription));
        $this->assertFalse($employee->can('delete', $subscription));
        $this->actingAs($employee)->post(route('subscriptions.bulk-changes.store'), [
            'field' => 'minimum_charge', 'mode' => 'amount', 'value' => 50, 'ids' => [$subscription->id],
        ])->assertSessionHasErrors('ids');
        $this->assertSame(25.0, (float) $subscription->fresh()->minimum_charge);
        $this->assertDatabaseCount('subscription_bulk_changes', 0);
    }
}
