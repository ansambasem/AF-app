<?php

namespace Tests\Feature\Users;

use App\Enums\PermissionKey;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminManagedPasswordTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith([UserRole::BranchAdmin])]
    #[TestWith([UserRole::Collector])]
    #[TestWith([UserRole::DataEntry])]
    #[TestWith([UserRole::Accountant])]
    #[TestWith([UserRole::FinancialAuditor])]
    public function test_non_super_administrators_cannot_change_their_own_password_through_either_route(UserRole $role): void
    {
        $user = User::factory()->withPermissions([PermissionKey::UpdateUsers])->create(['role' => $role]);
        $original = $user->password;

        $this->actingAs($user)->get(route('profile.edit'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canUpdatePassword', false));
        $this->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'NewPassword2026',
            'password_confirmation' => 'NewPassword2026',
        ])->assertForbidden();
        $this->put(route('users.update', $user), $this->passwordPayload($user))->assertForbidden();

        $this->assertSame($original, $user->fresh()->password);
        $this->assertDatabaseCount('notifications', 0);
    }

    #[TestWith([UserRole::Collector])]
    #[TestWith([UserRole::DataEntry])]
    #[TestWith([UserRole::Accountant])]
    #[TestWith([UserRole::FinancialAuditor])]
    public function test_branch_manager_can_reset_only_branch_staff_passwords_without_changing_username(UserRole $role): void
    {
        $branch = Branch::factory()->create();
        $manager = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);
        $staff = User::factory()->create(['branch_id' => $branch->id, 'role' => $role]);
        $username = $staff->username;

        if ($role === UserRole::FinancialAuditor) {
            $original = $staff->password;
            $this->actingAs($manager)->get(route('users.edit', $staff))->assertForbidden();
            $this->put(route('users.update', $staff), $this->passwordPayload($staff))->assertForbidden();
            $this->assertSame($original, $staff->fresh()->password);
            $this->assertSame($username, $staff->fresh()->username);

            return;
        }

        $this->actingAs($manager)->get(route('users.edit', $staff))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('user.canUpdatePassword', true));
        $this->put(route('users.update', $staff), $this->passwordPayload($staff))
            ->assertSessionHasNoErrors()->assertRedirect(route('users.index'));

        $this->assertTrue(Hash::check('NewPassword2026', $staff->fresh()->password));
        $this->assertSame($username, $staff->fresh()->username);
    }

    #[TestWith([UserRole::Collector])]
    #[TestWith([UserRole::DataEntry])]
    #[TestWith([UserRole::Accountant])]
    #[TestWith([UserRole::FinancialAuditor])]
    public function test_user_edit_permission_does_not_allow_staff_to_reset_another_users_password(UserRole $role): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->withPermissions([PermissionKey::UpdateUsers])->create(['branch_id' => $branch->id, 'role' => $role]);
        $target = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $original = $target->password;

        $this->actingAs($actor)->get(route('users.edit', $target))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('user.canUpdatePassword', false));
        $this->put(route('users.update', $target), $this->passwordPayload($target))->assertForbidden();

        $this->assertSame($original, $target->fresh()->password);

        $this->put(route('users.update', $target), ['name' => 'Updated Name', 'username' => $target->username])
            ->assertSessionHasNoErrors()->assertRedirect(route('users.index'));
        $this->assertSame('Updated Name', $target->fresh()->name);
        $this->assertSame($original, $target->fresh()->password);
    }

    public function test_branch_manager_cannot_reset_passwords_of_another_branch_or_other_administrators(): void
    {
        $branch = Branch::factory()->create();
        $manager = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);
        $otherManager = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);
        $foreignStaff = User::factory()->collector()->create();
        $administrator = User::factory()->superAdmin()->create();

        foreach ([$otherManager, $foreignStaff, $administrator] as $target) {
            $original = $target->password;
            $this->actingAs($manager)->put(route('users.update', $target), $this->passwordPayload($target))->assertForbidden();

            $this->assertSame($original, $target->fresh()->password);
        }
    }

    #[TestWith([UserRole::BranchAdmin])]
    #[TestWith([UserRole::FinancialAuditor])]
    public function test_super_administrator_can_reset_protected_users_passwords_and_still_change_their_own(UserRole $role): void
    {
        $administrator = User::factory()->superAdmin()->create();
        $manager = User::factory()->create(['role' => $role]);
        $username = $manager->username;

        $this->actingAs($administrator)->get(route('profile.edit'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canUpdatePassword', true));
        $this->put(route('users.update', $manager), $this->passwordPayload($manager))
            ->assertSessionHasNoErrors()->assertRedirect(route('users.index'));

        $this->assertTrue(Hash::check('NewPassword2026', $manager->fresh()->password));
        $this->assertSame($username, $manager->fresh()->username);
    }

    /** @return array{name: string, username: string, branch_id: int|null, password: string, password_confirmation: string} */
    private function passwordPayload(User $user): array
    {
        return [
            'name' => $user->name,
            'username' => $user->username,
            'branch_id' => $user->branch_id,
            'password' => 'NewPassword2026',
            'password_confirmation' => 'NewPassword2026',
        ];
    }
}
