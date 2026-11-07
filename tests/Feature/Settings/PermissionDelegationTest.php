<?php

namespace Tests\Feature\Settings;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\ActionCompleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PermissionDelegationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $collector;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionKey::cases() as $key) {
            Permission::firstOrCreate(['key' => $key->value], ['label' => $key->label()]);
        }

        $branch = Branch::factory()->create();
        $this->admin = User::factory()->superAdmin()->create();
        $this->manager = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $this->manager->permissions()->sync(Permission::idsFor([PermissionKey::ManagePermissions, PermissionKey::ViewSubscriptions, PermissionKey::RecordCollections]));
        $this->collector = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $this->collector->permissions()->sync([]);
    }

    public function test_a_manager_hands_on_only_the_permissions_the_super_admin_gave_them_the_right_to(): void
    {
        [$viewSubscriptions, $recordCollections] = Permission::idsFor([PermissionKey::ViewSubscriptions, PermissionKey::RecordCollections]);
        $this->keys([$viewSubscriptions]);

        $this->actingAs($this->manager->fresh())->put(route('settings.permissions.update'), ['permissions' => [$this->collector->id => [$viewSubscriptions, $recordCollections]]])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->collector->fresh()->hasPermission(PermissionKey::ViewSubscriptions));
        $this->assertFalse($this->collector->fresh()->hasPermission(PermissionKey::RecordCollections));
        $this->assertDatabaseHas('permission_user', ['user_id' => $this->collector->id, 'permission_id' => $viewSubscriptions, 'granted_by' => $this->manager->id, 'grantable' => false]);
    }

    public function test_taking_a_permission_from_the_manager_takes_it_from_whoever_they_handed_it_to(): void
    {
        [$viewSubscriptions, $recordCollections] = Permission::idsFor([PermissionKey::ViewSubscriptions, PermissionKey::RecordCollections]);
        $fromAdmin = User::factory()->collector()->create(['branch_id' => $this->collector->branch_id]);
        $fromAdmin->permissions()->sync([$viewSubscriptions]);
        $this->keys([$viewSubscriptions, $recordCollections]);
        $this->actingAs($this->manager->fresh())->put(route('settings.permissions.update'), ['permissions' => [$this->collector->id => [$viewSubscriptions, $recordCollections]]]);

        // Only the right to hand it on goes: what was handed on stays.
        $this->keys([$viewSubscriptions]);
        $this->assertTrue($this->collector->fresh()->hasPermission(PermissionKey::RecordCollections));

        // The permission itself goes: it goes from those the manager gave it to, not from those the Super Admin gave it to.
        $this->actingAs($this->admin)->put(route('settings.permissions.update'), [
            'permissions' => [$this->manager->id => Permission::idsFor([PermissionKey::ManagePermissions, PermissionKey::RecordCollections])],
        ])->assertSessionHasNoErrors();

        $this->assertFalse($this->collector->fresh()->hasPermission(PermissionKey::ViewSubscriptions));
        $this->assertTrue($this->collector->fresh()->hasPermission(PermissionKey::RecordCollections));
        $this->assertTrue($fromAdmin->fresh()->hasPermission(PermissionKey::ViewSubscriptions));
    }

    public function test_no_one_but_the_super_admin_makes_a_manager_or_gives_the_right_to_hand_on(): void
    {
        $managePermissions = Permission::idsFor([PermissionKey::ManagePermissions])[0];
        $this->keys([$managePermissions]);
        $this->assertDatabaseHas('permission_user', ['user_id' => $this->manager->id, 'permission_id' => $managePermissions, 'grantable' => false]);

        $this->actingAs($this->manager->fresh())->put(route('settings.permissions.update'), ['permissions' => [$this->collector->id => [$managePermissions]]]);
        $this->assertFalse($this->collector->fresh()->hasPermission(PermissionKey::ManagePermissions));

        $this->put(route('settings.permissions.update'), ['permissions' => [$this->collector->id => []], 'grantable' => [$this->collector->id => []]])
            ->assertSessionHasErrors('grantable');
    }

    public function test_the_super_admins_are_told_what_a_manager_handed_on(): void
    {
        Notification::fake();
        $viewSubscriptions = Permission::idsFor([PermissionKey::ViewSubscriptions])[0];
        $this->keys([$viewSubscriptions]);

        $this->actingAs($this->manager->fresh())->put(route('settings.permissions.update'), ['permissions' => [$this->collector->id => [$viewSubscriptions]]]);

        Notification::assertSentTo($this->admin, ActionCompleted::class, fn (ActionCompleted $notification): bool => $notification->action === 'permissions-delegated'
            && str_contains($notification->subject, $this->manager->name) && str_contains($notification->subject, $this->collector->name));
    }

    public function test_the_page_shows_the_super_admin_the_right_to_hand_on_and_who_handed_each_permission_on(): void
    {
        $viewSubscriptions = Permission::idsFor([PermissionKey::ViewSubscriptions])[0];
        $this->keys([$viewSubscriptions]);
        $this->actingAs($this->manager->fresh())->put(route('settings.permissions.update'), ['permissions' => [$this->collector->id => [$viewSubscriptions]]]);

        $this->actingAs($this->admin)->get(route('settings.permissions.edit', ['selected' => $this->manager->id]))->assertInertia(fn ($page) => $page
            ->where('selectedUser.grantableIds', [$viewSubscriptions])
            ->where("selectedUser.grantsGiven.{$viewSubscriptions}", 1)
            ->where('delegation.managePermissionsId', Permission::idsFor([PermissionKey::ManagePermissions])[0]));
        $this->get(route('settings.permissions.edit', ['selected' => $this->collector->id]))
            ->assertInertia(fn ($page) => $page->where("selectedUser.grantedBy.{$viewSubscriptions}", $this->manager->name));
        $this->actingAs($this->manager->fresh())->get(route('settings.permissions.edit'))->assertInertia(fn ($page) => $page->where('delegation', null));
    }

    /**
     * The Super Admin saves the manager's permissions as they are, with the right to hand on these.
     *
     * @param  array<int, int>  $permissionIds
     */
    private function keys(array $permissionIds): void
    {
        $this->actingAs($this->admin)->put(route('settings.permissions.update'), [
            'permissions' => [$this->manager->id => $this->manager->fresh()->permissions->modelKeys()],
            'grantable' => [$this->manager->id => $permissionIds],
        ])->assertSessionHasNoErrors();
    }
}
