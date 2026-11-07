<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\PermissionKey;
use App\Models\BankTransferAudit;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\TransactionAmendment;
use App\Models\User;
use App\Support\BranchClosingWorkflow;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AuditorPermissionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_super_admin_saves_the_employees_audit_work_and_its_branch_scope_together(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $auditor = User::factory()->financialAuditor()->create();
        $branch = Branch::factory()->create();
        $settings = ['scope' => 'selected', 'branch_ids' => [$branch->id], 'actions' => ['view_accounts', 'view_ledger']];

        $this->actingAs($admin)->put(route('settings.permissions.update'), $this->payload($auditor, $settings))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($settings, $auditor->fresh()->audit_settings);
        $this->get(route('settings.permissions.edit', ['selected' => $auditor->id]))->assertInertia(fn ($page) => $page
            ->where('selectedUser.branchName', $auditor->branch->name)
            ->where('selectedUser.auditSettings', $settings)
            ->where('auditOptions.actions.3.value', 'review_transfers'));
    }

    public function test_branch_admin_cannot_change_audit_work_even_for_their_own_employee(): void
    {
        $admin = User::factory()->branchAdmin()->create();
        $employee = User::factory()->collector()->for($admin->branch)->create();
        $original = $employee->permissions->modelKeys();

        $this->actingAs($admin)->put(route('settings.permissions.update'), $this->payload($employee, ['scope' => 'all', 'branch_ids' => [], 'actions' => ['approve_boxes']]))->assertSessionHasErrors('audit_settings');

        $this->assertNull($employee->fresh()->audit_settings);
        $this->assertSame($original, $employee->fresh()->permissions->modelKeys());
        $this->get(route('settings.permissions.edit'))->assertInertia(fn ($page) => $page->where('auditOptions', null));
    }

    public function test_selected_branches_limit_accounts_ledger_boxes_reports_and_direct_audit_requests(): void
    {
        [$auditor, $subscription, $payment, $box] = $this->closedPayment();
        $other = Branch::factory()->create();
        $visible = Subscription::factory()->for($other)->create();
        $visibleTransaction = SubscriptionTransaction::factory()->for($visible)->create();
        foreach ([$payment, $visibleTransaction] as $transaction) {
            TransactionAmendment::query()->create(['transaction_id' => $transaction->id, 'user_id' => $auditor->id, 'reason' => 'مراجعة', 'changes' => ['notes' => [null, 'مراجعة']]]);
        }
        $auditor->audit_settings = ['scope' => 'selected', 'branch_ids' => [$other->id], 'actions' => array_column(AuditAction::cases(), 'value')];
        $auditor->save();

        $this->actingAs($auditor)->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page->has('subscriptions.data', 1)->where('subscriptions.data.0.id', $visible->id));
        $this->get(route('subscriptions.statement', $subscription))->assertForbidden();
        $this->get(route('ledger.index', ['period' => 'all']))->assertInertia(fn ($page) => $page->has('entries.data', 1));
        $this->get(route('transaction-audit.index', ['period' => 'all']))->assertInertia(fn ($page) => $page->has('events.data', 1)
            ->where('filterOptions', fn ($groups): bool => collect(collect($groups)->firstWhere('key', 'user_id')['options'])->contains('value', (string) $auditor->id)));
        $this->get(route('payments.cash-boxes', ['branch_id' => $subscription->branch_id]))->assertNotFound();
        $this->get(route('payments.cash-boxes.report', ['box' => $box->id]))->assertNotFound();
        $this->post(route('payments.bank-audits.store', $payment->boxEntry), ['bank_amount' => '0', 'reason' => 'اختبار نطاق'])->assertNotFound();
        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertNotFound();

        $this->assertDatabaseCount('bank_transfer_audits', 0);
        $this->assertDatabaseCount('cash_box_audits', 0);
    }

    public function test_reviewing_transfers_does_not_grant_box_or_period_approval_or_printing(): void
    {
        [$auditor, $subscription, $payment, $box] = $this->closedPayment();
        $auditor->audit_settings = ['scope' => 'all', 'branch_ids' => [], 'actions' => ['review_transfers']];
        $auditor->save();

        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $payment->boxEntry), ['bank_amount' => '0', 'reason' => 'غير موجودة في البنك'])->assertSessionHasNoErrors();
        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertForbidden();
        $this->post(route('payments.period-audits.store'), [])->assertForbidden();
        $this->get(route('payments.cash-boxes.report', ['box' => $box->id]))->assertForbidden();
        $this->get(route('subscriptions.payments.receipt', [$subscription, $payment]))->assertForbidden();
        $this->get(route('subscriptions.index'))->assertForbidden();
        $this->get(route('ledger.index'))->assertForbidden();
        $this->get(route('transaction-audit.index'))->assertForbidden();
        $this->get(route('payments.cash-boxes', ['branch_id' => $subscription->branch_id, 'box' => $box->id]))->assertInertia(fn ($page) => $page
            ->where('boxes.0.canReviewTransfers', true)->where('boxes.0.canCompleteAudit', false)->where('boxes.0.canPrint', false)->where('periodAudit', null));

        $this->assertNull(BankTransferAudit::sole()->posted_at);
        $this->assertDatabaseCount('cash_box_audits', 0);
        $this->assertSame(-100.0, $subscription->balance());
    }

    public function test_an_approver_can_finalize_a_box_without_permission_to_edit_transfer_reviews(): void
    {
        [$auditor, $subscription, $payment, $box] = $this->closedPayment();
        $auditor->audit_settings = ['scope' => 'all', 'branch_ids' => [], 'actions' => ['approve_boxes']];
        $auditor->save();

        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $payment->boxEntry), ['bank_amount' => '0', 'reason' => 'غير موجودة'])->assertForbidden();
        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cash_box_audits', ['cash_box_id' => $box->id, 'audited_by' => $auditor->id]);
        $this->assertSame('matched', BankTransferAudit::sole()->outcome);
        $this->assertSame(-100.0, $subscription->balance());
    }

    public function test_revoking_the_master_permission_removes_access_even_with_the_auditor_role(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $auditor = User::factory()->financialAuditor()->create();

        $this->actingAs($admin)->put(route('settings.permissions.update'), ['permissions' => [$auditor->id => []]])->assertSessionHasNoErrors();
        $this->actingAs($auditor->fresh())->get(route('payments.cash-boxes'))->assertForbidden();
        $this->get(route('subscriptions.index'))->assertForbidden();

        $this->assertFalse($auditor->fresh()->canAuditAction(AuditAction::ApproveBoxes));
    }

    public function test_super_admin_assigns_effective_operational_permissions_to_a_former_auditor(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $auditor = User::factory()->financialAuditor()->create();
        $ids = Permission::idsFor([PermissionKey::AuditClosings, PermissionKey::RecordCollections]);

        $this->actingAs($admin)->put(route('settings.permissions.update'), ['permissions' => [$auditor->id => $ids]])->assertSessionHasNoErrors();

        $this->assertTrue($auditor->fresh()->hasPermission(PermissionKey::RecordCollections));
    }

    #[TestWith(['selected', [], [], 'اختر فرعًا واحدًا على الأقل للتدقيق.'])]
    #[TestWith(['all', [], ['approve_periods'], 'اعتماد الفترة يتطلب صلاحية اعتماد الصناديق.'])]
    public function test_invalid_audit_configuration_is_not_saved(string $scope, array $branchIds, array $actions, string $message): void
    {
        $admin = User::factory()->superAdmin()->create();
        $auditor = User::factory()->financialAuditor()->create();

        $this->actingAs($admin)->put(route('settings.permissions.update'), $this->payload($auditor, ['scope' => $scope, 'branch_ids' => $branchIds, 'actions' => $actions]))->assertSessionHasErrors(['audit_settings' => $message]);

        $this->assertNull($auditor->fresh()->audit_settings);
    }

    public function test_ledger_and_history_permissions_are_independent_from_subscriber_accounts(): void
    {
        $auditor = User::factory()->financialAuditor()->create(['audit_settings' => ['scope' => 'all', 'branch_ids' => [], 'actions' => ['view_ledger', 'view_history']]]);

        $this->actingAs($auditor)->get(route('ledger.index'))->assertOk();
        $this->get(route('transaction-audit.index'))->assertOk();
        $this->get(route('subscriptions.index'))->assertForbidden();
        $this->get(route('ledger.index', ['print' => 1]))->assertForbidden();
    }

    #[TestWith(['branch_ids', [9999999]])]
    #[TestWith(['actions', ['grant_permissions']])]
    public function test_unknown_branches_or_audit_actions_are_rejected(string $field, array $values): void
    {
        $admin = User::factory()->superAdmin()->create();
        $auditor = User::factory()->financialAuditor()->create();
        $settings = ['scope' => 'selected', 'branch_ids' => [$auditor->branch_id], 'actions' => ['view_accounts']];
        $settings[$field] = $values;

        $this->actingAs($admin)->put(route('settings.permissions.update'), $this->payload($auditor, $settings))->assertSessionHasErrors("audit_settings.{$auditor->id}.{$field}.0");

        $this->assertNull($auditor->fresh()->audit_settings);
    }

    public function test_audit_configuration_cannot_include_unexpected_keys_or_a_different_employee(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $auditor = User::factory()->financialAuditor()->create();
        $other = User::factory()->financialAuditor()->create();
        $settings = ['scope' => 'all', 'branch_ids' => [], 'actions' => ['view_accounts']];

        $this->actingAs($admin)->put(route('settings.permissions.update'), $this->payload($auditor, [...$settings, 'role' => 'super_admin']))->assertSessionHasErrors("audit_settings.{$auditor->id}");
        $payload = $this->payload($auditor, $settings);
        $payload['audit_settings'] = [$other->id => $settings];
        $this->put(route('settings.permissions.update'), $payload)->assertSessionHasErrors('audit_settings');

        $this->assertNull($auditor->fresh()->audit_settings);
        $this->assertNull($other->fresh()->audit_settings);
    }

    /** @return array<string, mixed> */
    private function payload(User $user, array $settings): array
    {
        return ['permissions' => [$user->id => Permission::idsFor([PermissionKey::AuditClosings])], 'audit_settings' => [$user->id => $settings]];
    }

    /** @return array{User, Subscription, SubscriptionTransaction, CashBox} */
    private function closedPayment(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 18)->setTime(10, 0));
        $branch = Branch::factory()->create();
        $admin = User::factory()->branchAdmin()->for($branch)->create();
        $collector = User::factory()->collector()->for($branch)->create();
        $auditor = User::factory()->financialAuditor()->create();
        $subscription = Subscription::factory()->for($branch)->create();
        $payment = SubscriptionTransaction::recordPayment($subscription, $collector, ['amount' => '100', 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'reference_number' => 'AUDIT-SCOPE']);
        $box = $payment->boxEntry->box;
        app(BranchClosingWorkflow::class)->receive($box, $admin);

        return [$auditor, $subscription, $payment, $box];
    }
}
