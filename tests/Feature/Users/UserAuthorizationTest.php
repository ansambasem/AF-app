<?php

namespace Tests\Feature\Users;

use App\Enums\PermissionKey;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_all_users_across_branches(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        User::factory()->collector()->create(['branch_id' => $branchA->id, 'name' => 'From Branch A']);
        User::factory()->collector()->create(['branch_id' => $branchB->id, 'name' => 'From Branch B']);

        $response = $this->actingAs($superAdmin)->get(route('users.index'));

        $response->assertOk();
        $response->assertSee('From Branch A');
        $response->assertSee('From Branch B');
    }

    public function test_super_admin_creates_an_employee_without_automatically_granting_management(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create();

        $response = $this->actingAs($superAdmin)->post(route('users.store'), [
            'name' => 'Alice Admin',
            'username' => 'alice.admin',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'branch_id' => $branch->id,
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', [
            'username' => 'alice.admin',
            'role' => UserRole::Collector->value,
            'branch_id' => $branch->id,
        ]);
    }

    public function test_branch_admin_only_sees_users_in_their_own_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $branchA->id]);
        User::factory()->collector()->create(['branch_id' => $branchA->id, 'name' => 'In My Branch']);
        User::factory()->collector()->create(['branch_id' => $branchB->id, 'name' => 'In Other Branch']);

        $response = $this->actingAs($branchAdmin)->get(route('users.index'));

        $response->assertOk();
        $response->assertSee('In My Branch');
        $response->assertDontSee('In Other Branch');
    }

    public function test_branch_admin_can_create_a_staff_member_in_their_own_branch(): void
    {
        $ownBranch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $ownBranch->id]);

        // Attempt to tamper: request a different branch — must be ignored.
        $response = $this->actingAs($branchAdmin)->post(route('users.store'), [
            'name' => 'Dana Entry',
            'username' => 'dana.entry',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'branch_id' => $otherBranch->id,
        ]);

        $response->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', [
            'username' => 'dana.entry',
            'branch_id' => $ownBranch->id,
        ]);
    }

    public function test_branch_admin_cannot_create_a_user_with_a_super_privileged_role(): void
    {
        $ownBranch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $ownBranch->id]);

        // Attempt to tamper: request a super-privileged role.
        $response = $this->actingAs($branchAdmin)->post(route('users.store'), [
            'name' => 'Bob Admin',
            'username' => 'bob.admin',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => UserRole::BranchAdmin->value,
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['username' => 'bob.admin']);
    }

    public function test_collector_with_create_users_permission_can_create_a_staff_member_in_their_own_branch(): void
    {
        $ownBranch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $collector = User::factory()->collector()->create(['branch_id' => $ownBranch->id]);
        $createUsers = Permission::create(['key' => PermissionKey::CreateUsers->value, 'label' => PermissionKey::CreateUsers->label()]);
        $collector->permissions()->attach($createUsers);

        // Attempt to tamper: request a different branch — must be ignored.
        $response = $this->actingAs($collector)->post(route('users.store'), [
            'name' => 'Dana Entry',
            'username' => 'dana.entry',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'branch_id' => $otherBranch->id,
        ]);

        $response->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', [
            'username' => 'dana.entry',
            'branch_id' => $ownBranch->id,
        ]);
    }

    public function test_collector_with_create_users_permission_cannot_create_a_user_with_a_super_privileged_role(): void
    {
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $createUsers = Permission::create(['key' => PermissionKey::CreateUsers->value, 'label' => PermissionKey::CreateUsers->label()]);
        $collector->permissions()->attach($createUsers);

        // Attempt to tamper: request a super-privileged role.
        $response = $this->actingAs($collector)->post(route('users.store'), [
            'name' => 'Bob Admin',
            'username' => 'bob.admin',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => UserRole::BranchAdmin->value,
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['username' => 'bob.admin']);
    }

    public function test_collector_without_create_users_permission_cannot_create_a_user(): void
    {
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);

        $this->actingAs($collector)
            ->get(route('users.create'))
            ->assertForbidden();

        $this->actingAs($collector)->post(route('users.store'), [
            'name' => 'Should Not Be Created',
            'username' => 'should.not',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['username' => 'should.not']);
    }

    public function test_branch_admin_cannot_update_a_user_from_another_branch(): void
    {
        $ownBranch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $ownBranch->id]);
        $foreignCollector = User::factory()->collector()->create(['branch_id' => $otherBranch->id]);

        $this->actingAs($branchAdmin)
            ->get(route('users.edit', $foreignCollector))
            ->assertForbidden();

        $this->actingAs($branchAdmin)
            ->put(route('users.update', $foreignCollector), ['name' => 'Hacked Name'])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', [
            'id' => $foreignCollector->id,
            'name' => 'Hacked Name',
        ]);
    }

    public function test_branch_admin_cannot_escalate_a_collectors_role_via_update(): void
    {
        $branch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);

        $this->actingAs($branchAdmin)->put(route('users.update', $collector), [
            'name' => $collector->name,
            'username' => $collector->username,
            'role' => UserRole::SuperAdmin->value,
            'branch_id' => $branch->id,
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseHas('users', [
            'id' => $collector->id,
            'role' => UserRole::Collector->value,
        ]);
    }

    public function test_branch_admin_cannot_change_system_role_metadata(): void
    {
        $branch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);

        $this->actingAs($branchAdmin)->put(route('users.update', $collector), [
            'name' => $collector->name,
            'username' => $collector->username,
            'role' => UserRole::Accountant->value,
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseHas('users', [
            'id' => $collector->id,
            'role' => UserRole::Collector->value,
        ]);
    }

    public function test_branch_admin_can_manage_data_entry_but_cannot_manage_financial_auditors(): void
    {
        $branch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $branch->id]);
        $auditor = User::factory()->financialAuditor()->create(['branch_id' => $branch->id]);

        $this->actingAs($branchAdmin)->get(route('users.edit', $dataEntry))->assertOk();
        $this->actingAs($branchAdmin)->get(route('users.edit', $auditor))->assertForbidden();
    }

    public function test_collector_has_no_access_to_user_management(): void
    {
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);

        $this->actingAs($collector)->get(route('users.index'))->assertForbidden();
        $this->actingAs($collector)->get(route('users.create'))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('users.index'))
            ->assertRedirect(route('login'));
    }
}
