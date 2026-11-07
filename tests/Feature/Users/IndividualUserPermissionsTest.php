<?php

namespace Tests\Feature\Users;

use App\Enums\PermissionKey;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserType;
use App\Support\BranchClosingAccess;
use App\Support\FinancialReportAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndividualUserPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_employees_start_without_permissions_and_job_titles_grant_no_access(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create();
        $type = UserType::factory()->create(['name' => 'مدقق مالي']);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'New Employee', 'username' => 'new.employee', 'branch_id' => $branch->id,
            'user_type_id' => $type->id, 'password' => 'Tulip2026ab', 'password_confirmation' => 'Tulip2026ab',
        ])->assertSessionHasNoErrors()->assertRedirect(route('users.index'));

        $employee = User::where('username', 'new.employee')->sole();
        $this->assertSame($type->id, $employee->user_type_id);
        $this->assertSame(0, $employee->permissions()->count());
        $this->assertFalse($employee->hasFinancialAuditAccess());
        $this->assertFalse($employee->can('manage', Permission::class));
    }

    public function test_changing_the_job_title_preserves_individual_grants(): void
    {
        $employee = User::factory()->accountant()->create();
        $employee->permissions()->sync(Permission::idsFor([PermissionKey::ViewMeterReadings]));
        $type = UserType::factory()->create(['name' => 'مدير فرع']);

        $this->actingAs(User::factory()->superAdmin()->create())->put(route('users.update', $employee), [
            'name' => $employee->name, 'username' => $employee->username, 'branch_id' => $employee->branch_id, 'user_type_id' => $type->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($type->id, $employee->fresh()->user_type_id);
        $this->assertSame([PermissionKey::ViewMeterReadings->value], $employee->permissions()->pluck('key')->all());
        $this->assertFalse($employee->fresh()->can('manage', Permission::class));
    }

    public function test_a_delegated_manager_can_add_an_employee_then_grant_and_revoke_their_work_permission(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $manager = User::factory()->collector()->create();
        $manager->permissions()->sync([]);
        $delegated = Permission::idsFor([PermissionKey::CreateUsers, PermissionKey::ManagePermissions, PermissionKey::ViewSubscriptions]);
        $viewSubscriptions = Permission::query()->where('key', PermissionKey::ViewSubscriptions->value)->sole();

        $this->actingAs($admin)->put(route('settings.permissions.update'), ['permissions' => [$manager->id => $delegated], 'grantable' => [$manager->id => [$viewSubscriptions->id]]])->assertSessionHasNoErrors();
        $this->actingAs($manager->fresh())->get(route('users.index'))->assertInertia(fn ($page) => $page->where('canCreate', true)->where('can.manageSettings', true));
        $this->post(route('users.store'), [
            'name' => 'موظف الفرع', 'username' => 'delegated.employee',
            'password' => 'Tulip2026ab', 'password_confirmation' => 'Tulip2026ab',
        ])->assertSessionHasNoErrors()->assertRedirect(route('users.index'));
        $employee = User::query()->where('username', 'delegated.employee')->sole();
        $this->assertSame($manager->branch_id, $employee->branch_id);
        $this->assertSame(0, $employee->permissions()->count());

        $this->put(route('settings.permissions.update'), ['permissions' => [$employee->id => [$viewSubscriptions->id]]])->assertSessionHasNoErrors();
        $this->actingAs($employee->fresh())->get(route('subscriptions.index'))->assertOk();
        $this->actingAs($manager->fresh())->put(route('settings.permissions.update'), ['permissions' => [$employee->id => []]])->assertSessionHasNoErrors();
        $this->assertSame(0, $employee->permissions()->count());
        $this->actingAs($employee->fresh())->get(route('subscriptions.index'))->assertForbidden();

        $this->actingAs($admin)->put(route('settings.permissions.update'), ['permissions' => [$manager->id => Permission::idsFor([PermissionKey::CreateUsers])]])->assertSessionHasNoErrors();
        $this->actingAs($manager->fresh())->get(route('users.index'))->assertInertia(fn ($page) => $page->where('canCreate', true)->where('can.manageSettings', false));
        $this->put(route('settings.permissions.update'), ['permissions' => [$employee->id => [$viewSubscriptions->id]]])->assertForbidden();
    }

    public function test_account_edits_cannot_elevate_a_system_role(): void
    {
        $employee = User::factory()->collector()->create();

        $this->actingAs(User::factory()->superAdmin()->create())->put(route('users.update', $employee), [
            'name' => $employee->name, 'username' => $employee->username, 'branch_id' => $employee->branch_id, 'role' => UserRole::SuperAdmin->value,
        ])->assertSessionHasErrors('role');

        $this->assertSame(UserRole::Collector, $employee->fresh()->role);
    }

    public function test_a_former_auditor_can_receive_operational_permissions_and_loses_them_when_revoked(): void
    {
        $employee = User::factory()->financialAuditor()->create();
        $permissionIds = Permission::idsFor([PermissionKey::AuditClosings, PermissionKey::ViewSubscriptions, PermissionKey::UpdateSubscriptionKilowattPrice]);

        $this->actingAs(User::factory()->superAdmin()->create())->put(route('settings.permissions.update'), ['permissions' => [$employee->id => $permissionIds]])->assertSessionHasNoErrors();

        $this->assertTrue($employee->fresh()->hasPermission(PermissionKey::UpdateSubscriptionKilowattPrice));
        $this->actingAs($employee->fresh())->get(route('subscriptions.index'))->assertOk();
        $employee->permissions()->sync([]);
        $this->actingAs($employee->fresh())->get(route('subscriptions.index'))->assertForbidden();
    }

    public function test_permission_management_is_revocable_and_scoped_to_ordinary_employees_of_the_same_branch(): void
    {
        $manager = User::factory()->collector()->create();
        $manager->permissions()->sync(Permission::idsFor([PermissionKey::ManagePermissions, PermissionKey::ViewSubscriptions]));
        $ownEmployee = User::factory()->for($manager->branch)->create();
        $otherBranchEmployee = User::factory()->create();
        $otherManager = User::factory()->branchAdmin()->for($manager->branch)->create();

        $this->actingAs($manager->fresh())->get(route('settings.permissions.edit'))->assertInertia(fn ($page) => $page
            ->where('users.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$ownEmployee->id])
            ->where('roleTemplates', []));

        $this->assertFalse($manager->fresh()->can('update', $otherBranchEmployee));
        $this->assertFalse($manager->fresh()->can('update', $otherManager));
        $manager->permissions()->sync([]);
        $this->actingAs($manager->fresh())->get(route('settings.permissions.edit'))->assertForbidden();
    }

    public function test_migration_converts_implicit_management_without_replacing_grants_or_job_titles(): void
    {
        $manager = User::factory()->branchAdmin()->create();
        $type = UserType::factory()->create(['name' => 'مشرف التحصيل']);
        $manager->update(['user_type_id' => $type->id]);
        $manager->permissions()->sync(Permission::idsFor([PermissionKey::ViewSubscriptions]));
        $migration = require database_path('migrations/2026_10_20_071427_convert_user_roles_to_individual_permissions.php');

        $migration->up();
        $migration->up();

        $this->assertSame($type->id, $manager->fresh()->user_type_id);
        $this->assertSame(5, $manager->permissions()->count());
        $this->assertTrue($manager->fresh()->can('manage', Permission::class));
        $this->assertTrue(BranchClosingAccess::manage($manager->fresh(), $manager->branch_id));
        $manager->permissions()->sync([]);
        $this->assertFalse($manager->fresh()->can('manage', Permission::class));
        $this->assertFalse(BranchClosingAccess::manage($manager->fresh(), $manager->branch_id));
    }

    public function test_migration_preserves_historical_report_access_without_adding_reports_to_audit_only_accounts(): void
    {
        $employee = User::factory()->collector()->create();
        $employee->permissions()->sync(Permission::idsFor([PermissionKey::PrepareClosings]));
        $auditor = User::factory()->financialAuditor()->create();
        $migration = require database_path('migrations/2026_10_20_071427_convert_user_roles_to_individual_permissions.php');

        $migration->up();

        $this->assertTrue(FinancialReportAccess::view($employee->fresh()));
        $this->assertFalse(FinancialReportAccess::viewAllBranches($employee->fresh()));
        $this->assertFalse(FinancialReportAccess::view($auditor->fresh()));
    }
}
