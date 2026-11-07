<?php

namespace Tests\Feature\Users;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyWideAdministratorTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Tulip2026ab';

    public function test_a_super_admin_adds_another_super_admin_over_every_branch(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'مدير عام ثانٍ', 'username' => 'second.head', 'company_wide' => true,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasNoErrors();

        $head = User::query()->where('username', 'second.head')->sole();
        $this->assertTrue($head->isSuperAdmin());
        $this->assertNull($head->branch_id);
        $this->assertTrue($head->hasPermission(PermissionKey::ManageBranchDomains));
        $this->assertTrue($head->canEnterBranch(Branch::factory()->create()));
    }

    public function test_an_employee_is_made_a_super_admin_and_sent_back_to_one_branch(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create();
        $employee = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);
        $fields = ['name' => $employee->name, 'username' => $employee->username, 'is_active' => true];

        $this->actingAs($admin)->put(route('users.update', $employee), [...$fields, 'company_wide' => true])->assertSessionHasNoErrors();
        $this->assertTrue($employee->fresh()->isSuperAdmin());
        $this->assertNull($employee->fresh()->branch_id);

        $this->put(route('users.update', $employee), [...$fields, 'company_wide' => false])->assertSessionHasErrors('branch_id');
        $this->put(route('users.update', $employee), [...$fields, 'company_wide' => false, 'branch_id' => $branch->id])->assertSessionHasNoErrors();
        $this->assertFalse($employee->fresh()->isSuperAdmin());
        $this->assertSame($branch->id, $employee->fresh()->branch_id);
    }

    public function test_no_one_sends_their_own_account_to_a_branch(): void
    {
        $admin = User::factory()->superAdmin()->create();
        User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($admin)->put(route('users.update', $admin), [
            'name' => $admin->name, 'username' => $admin->username, 'is_active' => true, 'company_wide' => false, 'branch_id' => $branch->id,
        ])->assertSessionHasErrors('company_wide');
        $this->assertTrue($admin->fresh()->isSuperAdmin());
    }

    public function test_the_last_working_super_admin_cannot_be_stopped_or_sent_to_a_branch(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $head = User::factory()->superAdmin()->create();
        $head->forceFill(['is_active' => false])->save();
        $branch = Branch::factory()->create();
        $fields = ['name' => $head->name, 'username' => $head->username];

        // Turning a stopped one back on, or moving them, leaves the working one in place.
        $this->actingAs($admin)->put(route('users.update', $head), [...$fields, 'is_active' => true])->assertSessionHasNoErrors();
        $admin->forceFill(['is_active' => false])->save();

        $this->actingAs($head->fresh())->put(route('users.update', $admin), ['name' => $admin->name, 'username' => $admin->username, 'is_active' => false, 'company_wide' => false, 'branch_id' => $branch->id])
            ->assertSessionHasNoErrors();
        $this->put(route('users.update', $head), [...$fields, 'is_active' => true, 'company_wide' => false, 'branch_id' => $branch->id])
            ->assertSessionHasErrors('company_wide');
    }

    public function test_only_a_super_admin_can_make_one(): void
    {
        $branch = Branch::factory()->create();
        $manager = User::factory()->branchAdmin()->withPermissions([PermissionKey::CreateUsers])->create(['branch_id' => $branch->id]);

        $this->actingAs($manager)->post(route('users.store'), [
            'name' => 'محاولة', 'username' => 'not.a.head', 'company_wide' => true,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ]);

        $created = User::query()->where('username', 'not.a.head')->first();
        $this->assertTrue($created === null || (! $created->isSuperAdmin() && $created->branch_id === $branch->id));
    }
}
