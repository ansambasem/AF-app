<?php

namespace Tests\Feature\Settings;

use App\Enums\PermissionKey;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function seedPermissions(): void
    {
        // Permission rows come from the seeder, one per PermissionKey.
        foreach (PermissionKey::cases() as $key) {
            Permission::firstOrCreate(['key' => $key->value], ['label' => $key->label()]);
        }
    }

    public function test_permanent_transaction_deletion_permission_is_installed_without_a_default_grant(): void
    {
        $this->seedPermissions();
        $branchAdmin = User::factory()->branchAdmin()->grantingWhatTheyHold()->create();

        $this->assertDatabaseHas('permissions', [
            'key' => PermissionKey::ForceDeleteTransactions->value,
            'label' => PermissionKey::ForceDeleteTransactions->label(),
        ]);
        $this->assertFalse($branchAdmin->hasPermission(PermissionKey::ForceDeleteTransactions));
        $this->assertTrue(User::factory()->superAdmin()->create()->hasPermission(PermissionKey::ForceDeleteTransactions));
    }

    public function test_super_admin_can_view_the_permissions_settings_page(): void
    {
        $this->seedPermissions();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('settings.permissions.edit'))
            ->assertOk();
    }

    public function test_branch_admin_can_view_the_permissions_settings_page(): void
    {
        $this->seedPermissions();
        $branch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->grantingWhatTheyHold()->create(['branch_id' => $branch->id]);

        $this->actingAs($branchAdmin)
            ->get(route('settings.permissions.edit'))
            ->assertOk();
    }

    public function test_branch_admin_only_sees_their_own_branch_staff_on_the_permissions_page(): void
    {
        $this->seedPermissions();
        $ownBranch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->grantingWhatTheyHold()->create(['branch_id' => $ownBranch->id]);
        User::factory()->collector()->create(['branch_id' => $ownBranch->id, 'name' => 'My Branch Collector']);
        User::factory()->collector()->create(['branch_id' => $otherBranch->id, 'name' => 'Other Branch Collector']);
        User::factory()->branchAdmin()->grantingWhatTheyHold()->create(['branch_id' => $ownBranch->id, 'name' => 'Peer Branch Admin']);

        $response = $this->actingAs($branchAdmin)->get(route('settings.permissions.edit'));

        $response->assertOk();
        $response->assertSee('My Branch Collector');
        $response->assertDontSee('Other Branch Collector');
        $response->assertDontSee('Peer Branch Admin');
    }

    public function test_branch_admin_can_grant_a_permission_to_their_own_branch_staff(): void
    {
        $this->seedPermissions();
        $branch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->grantingWhatTheyHold()->create(['branch_id' => $branch->id]);
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $viewSubscriptions = Permission::where('key', PermissionKey::ViewSubscriptions->value)->firstOrFail();

        $this->actingAs($branchAdmin)->put(route('settings.permissions.update'), [
            'permissions' => [
                $collector->id => [$viewSubscriptions->id],
            ],
        ])->assertRedirect(route('settings.permissions.edit'));

        $this->assertTrue($collector->fresh()->hasPermission(PermissionKey::ViewSubscriptions));
    }

    public function test_branch_admin_cannot_grant_a_permission_to_another_branchs_staff(): void
    {
        $this->seedPermissions();
        $ownBranch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->grantingWhatTheyHold()->create(['branch_id' => $ownBranch->id]);
        $foreignCollector = User::factory()->collector()->create(['branch_id' => $otherBranch->id]);
        $viewSubscriptions = Permission::where('key', PermissionKey::ViewSubscriptions->value)->firstOrFail();

        $this->actingAs($branchAdmin)->put(route('settings.permissions.update'), [
            'permissions' => [
                $foreignCollector->id => [$viewSubscriptions->id],
            ],
        ])->assertRedirect(route('settings.permissions.edit'));

        $this->assertFalse($foreignCollector->fresh()->hasPermission(PermissionKey::ViewSubscriptions));
    }

    public function test_branch_admin_cannot_change_a_peer_branch_admins_permissions(): void
    {
        $this->seedPermissions();
        $branch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->grantingWhatTheyHold()->create(['branch_id' => $branch->id]);
        $peerBranchAdmin = User::factory()->branchAdmin()->grantingWhatTheyHold()->create(['branch_id' => $branch->id]);

        $this->actingAs($branchAdmin)->put(route('settings.permissions.update'), [
            'permissions' => [
                $peerBranchAdmin->id => [],
            ],
        ])->assertRedirect(route('settings.permissions.edit'));

        $this->assertTrue($peerBranchAdmin->fresh()->hasPermission(PermissionKey::ViewSubscriptions));
    }

    public function test_super_admin_sees_every_permission_on_the_permissions_page(): void
    {
        $this->seedPermissions();
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->get(route('settings.permissions.edit'));

        $response->assertInertia(fn ($page) => $page->where('permissionGroups', fn ($groups): bool => collect($groups)->pluck('key')->all() === [
            'subscriptions', 'meter_boxes', 'circuit_breakers', 'tariffs', 'meter_readings', 'reading_assignments', 'collections', 'financial_audit', 'reports',
            'disconnections', 'messages', 'print_templates', 'users', 'user_types', 'branches', 'governorates', 'areas', 'sub_areas',
        ]));
    }

    public function test_each_kind_of_record_can_be_granted_its_own_delete_permission(): void
    {
        $this->seedPermissions();

        $response = $this->actingAs(User::factory()->superAdmin()->create())->get(route('settings.permissions.edit'));

        $response->assertInertia(fn ($page) => $page->where(
            'permissionGroups',
            fn ($groups): bool => collect($groups)
                ->reject(fn (array $group): bool => in_array($group['key'], ['meter_readings', 'reading_assignments', 'collections', 'financial_audit', 'reports', 'disconnections', 'messages', 'print_templates'], true))
                ->every(fn (array $group): bool => collect($group['actions'])->contains('action', 'delete')),
        ));
    }

    public function test_editing_and_deleting_transactions_are_granted_under_collections_on_the_permissions_page(): void
    {
        $this->seedPermissions();

        $response = $this->actingAs(User::factory()->superAdmin()->create())->get(route('settings.permissions.edit'));

        $response->assertInertia(fn ($page) => $page->where(
            'permissionGroups',
            fn ($groups): bool => collect(collect($groups)->firstWhere('key', 'collections')['actions'])->pluck('action')->all() === [
                'view', 'record', 'record_cash', 'record_transfer', 'close_boxes', 'manage_box_periods', 'manage_branch_closings', 'charge', 'discount', 'clearing', 'correct', 'amend', 'refund', 'delete', 'force_delete',
            ],
        ));
    }

    public function test_the_unused_confirm_collections_permission_is_removed_with_its_grants(): void
    {
        $this->seedPermissions();
        $confirm = Permission::create(['key' => 'collections.confirm', 'label' => 'Confirm Collections']);
        $branchAdmin = User::factory()->branchAdmin()->grantingWhatTheyHold()->create();
        $branchAdmin->permissions()->syncWithoutDetaching([$confirm->id, Permission::where('key', PermissionKey::ViewCollections->value)->value('id')]);

        (require database_path('migrations/2026_10_22_113315_remove_confirm_collections_permission.php'))->up();

        $this->assertDatabaseMissing('permissions', ['key' => 'collections.confirm']);
        $this->assertDatabaseMissing('permission_user', ['permission_id' => $confirm->id]);
        $this->assertTrue($branchAdmin->fresh()->hasPermission(PermissionKey::ViewCollections));
    }

    public function test_report_scope_is_granted_without_closing_controls(): void
    {
        $this->seedPermissions();
        $branchAdmin = User::factory()->branchAdmin()->grantingWhatTheyHold()->create();
        $reportActions = fn ($groups): array => collect(collect($groups)->firstWhere('key', 'reports')['actions'])->pluck('action')->all();

        $this->actingAs(User::factory()->superAdmin()->create())->get(route('settings.permissions.edit'))
            ->assertInertia(fn ($page) => $page->where('permissionGroups', fn ($groups): bool => $reportActions($groups) === ['view', 'view_all', 'branch_performance', 'debt_aging', 'transaction_audit', 'export']));
        $this->actingAs($branchAdmin)->get(route('settings.permissions.edit'))
            ->assertInertia(fn ($page) => $page->where('permissionGroups', fn ($groups): bool => $reportActions($groups) === ['view', 'branch_performance', 'debt_aging', 'transaction_audit', 'export']));
    }

    public function test_financial_audit_is_a_separate_super_admin_delegation_and_does_not_grant_payment_entry(): void
    {
        $this->seedPermissions();
        $admin = User::factory()->superAdmin()->create();
        $employee = User::factory()->collector()->withPermissions([])->create();
        $audit = Permission::query()->where('key', PermissionKey::AuditClosings->value)->sole();

        $this->actingAs($admin)->get(route('settings.permissions.edit', ['selected' => $employee->id]))->assertInertia(fn ($page) => $page
            ->where('selectedUser.auditSettingsConfigured', false)
            ->where('permissionGroups', fn ($groups): bool => collect(collect($groups)->firstWhere('key', 'financial_audit')['actions'])->pluck('permission.key')->all() === ['closings.audit', 'closings.view_all']));
        $this->put(route('settings.permissions.update'), [
            'permissions' => [$employee->id => [$audit->id]],
            'audit_settings' => [$employee->id => ['scope' => 'selected', 'branch_ids' => [$employee->branch_id], 'actions' => ['review_transfers']]],
        ])->assertSessionHasNoErrors();
        $employee = $employee->fresh();
        $this->assertTrue($employee->hasFinancialAuditAccess());
        $this->assertFalse($employee->can('recordAnyPayment', Subscription::class));
        $this->assertSame(['review_transfers'], $employee->auditSettings()['actions']);

        $manager = User::factory()->branchAdmin()->grantingWhatTheyHold()->create();
        $this->actingAs($manager)->get(route('settings.permissions.edit'))->assertInertia(fn ($page) => $page
            ->where('permissionGroups', fn ($groups): bool => ! collect($groups)->contains('key', 'financial_audit')));
    }

    public function test_branch_admin_does_not_see_company_wide_permissions_on_the_permissions_page(): void
    {
        $this->seedPermissions();
        $branch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->grantingWhatTheyHold()->create(['branch_id' => $branch->id]);
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $viewSubscriptions = Permission::where('key', PermissionKey::ViewSubscriptions->value)->firstOrFail();
        $viewAreas = Permission::where('key', PermissionKey::ViewAreas->value)->firstOrFail();
        $collector->permissions()->sync([$viewSubscriptions->id, $viewAreas->id]);

        $response = $this->actingAs($branchAdmin)->get(route('settings.permissions.edit'));

        $response->assertInertia(fn ($page) => $page
            ->where('permissionGroups', fn ($groups): bool => collect($groups)->pluck('key')->all() === [
                'subscriptions', 'meter_boxes', 'circuit_breakers', 'tariffs', 'meter_readings', 'reading_assignments', 'collections', 'reports', 'disconnections', 'messages', 'users', 'sub_areas',
            ])
            ->where('selectedUser.permissionIds', [$viewSubscriptions->id]));
    }

    public function test_branch_admin_cannot_grant_a_company_wide_permission(): void
    {
        $this->seedPermissions();
        $branch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->grantingWhatTheyHold()->create(['branch_id' => $branch->id]);
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $viewSubscriptions = Permission::where('key', PermissionKey::ViewSubscriptions->value)->firstOrFail();
        $createBranches = Permission::where('key', PermissionKey::CreateBranches->value)->firstOrFail();

        $this->actingAs($branchAdmin)->put(route('settings.permissions.update'), [
            'permissions' => [
                $collector->id => [$viewSubscriptions->id, $createBranches->id],
            ],
        ])->assertRedirect(route('settings.permissions.edit'));

        $collector = $collector->fresh();
        $this->assertTrue($collector->hasPermission(PermissionKey::ViewSubscriptions));
        $this->assertFalse($collector->hasPermission(PermissionKey::CreateBranches));
    }

    public function test_branch_admin_saving_permissions_keeps_company_wide_grants_from_the_super_admin(): void
    {
        $this->seedPermissions();
        $branch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->grantingWhatTheyHold()->create(['branch_id' => $branch->id]);
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $viewGovernorates = Permission::where('key', PermissionKey::ViewGovernorates->value)->firstOrFail();
        $viewSubscriptions = Permission::where('key', PermissionKey::ViewSubscriptions->value)->firstOrFail();
        $collector->permissions()->attach($viewGovernorates);

        $this->actingAs($branchAdmin)->put(route('settings.permissions.update'), [
            'permissions' => [
                $collector->id => [$viewSubscriptions->id],
            ],
        ])->assertRedirect(route('settings.permissions.edit'));

        $collector = $collector->fresh();
        $this->assertTrue($collector->hasPermission(PermissionKey::ViewSubscriptions));
        $this->assertTrue($collector->hasPermission(PermissionKey::ViewGovernorates));
    }

    public function test_super_admin_can_take_a_default_permission_away_from_a_branch_admin(): void
    {
        $this->seedPermissions();
        $superAdmin = User::factory()->superAdmin()->create();
        $branchAdmin = User::factory()->branchAdmin()->grantingWhatTheyHold()->create();
        $viewSubscriptions = Permission::where('key', PermissionKey::ViewSubscriptions->value)->firstOrFail();

        $this->actingAs($superAdmin)->put(route('settings.permissions.update'), [
            'permissions' => [
                $branchAdmin->id => [$viewSubscriptions->id],
            ],
        ])->assertRedirect(route('settings.permissions.edit'));

        $this->actingAs($branchAdmin->fresh())
            ->get(route('subscriptions.create'))
            ->assertForbidden();
    }

    public function test_branch_admin_cannot_grant_a_permission_they_do_not_hold(): void
    {
        $this->seedPermissions();
        $branch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->grantingWhatTheyHold()->create(['branch_id' => $branch->id]);
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $viewTariffs = Permission::where('key', PermissionKey::ViewTariffs->value)->firstOrFail();
        $createTariffs = Permission::where('key', PermissionKey::CreateTariffs->value)->firstOrFail();

        $this->actingAs($branchAdmin)->put(route('settings.permissions.update'), [
            'permissions' => [
                $collector->id => [$viewTariffs->id, $createTariffs->id],
            ],
        ])->assertRedirect(route('settings.permissions.edit'));

        $collector = $collector->fresh();
        $this->assertTrue($collector->hasPermission(PermissionKey::ViewTariffs));
        $this->assertFalse($collector->hasPermission(PermissionKey::CreateTariffs));
    }

    public function test_the_editor_shows_the_selected_employees_permissions(): void
    {
        $this->seedPermissions();
        $superAdmin = User::factory()->superAdmin()->create();
        User::factory()->collector()->create(['name' => 'Aaron Collector']);
        $selected = User::factory()->collector()->create(['name' => 'Zed Collector']);
        $viewBranches = Permission::where('key', PermissionKey::ViewBranches->value)->firstOrFail();
        $selected->permissions()->sync([$viewBranches->id]);

        $response = $this->actingAs($superAdmin)->get(route('settings.permissions.edit', ['selected' => $selected->id]));

        $response->assertInertia(fn ($page) => $page
            ->where('selectedUser.id', $selected->id)
            ->where('selectedUser.name', 'Zed Collector')
            ->where('selectedUser.permissionIds', [$viewBranches->id]));
    }

    public function test_the_page_lists_individual_grants_without_automatic_role_templates(): void
    {
        $this->seedPermissions();
        $branch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->grantingWhatTheyHold()->create(['branch_id' => $branch->id]);
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $branch->id]);
        $transferPermission = Permission::where('key', PermissionKey::RecordTransferPayments->value)->firstOrFail();
        $branchAdmin->permissions()->detach($transferPermission);
        $dataEntry->permissions()->attach($transferPermission);
        $idsOf = fn (array $keys): array => Permission::whereIn('key', array_map(fn (PermissionKey $key) => $key->value, $keys))->pluck('id')->sort()->values()->all();
        $sorted = fn (array $ids): array => collect($ids)->sort()->values()->all();

        $this->actingAs($branchAdmin)
            ->get(route('settings.permissions.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('users.data.0.role', 'data_entry')
                // Grants outside the actor's scope are shown separately and cannot be edited or copied.
                ->where('users.data.0.permissionIds', fn ($ids): bool => $sorted($ids->all()) === $idsOf(UserRole::DataEntry->starterPermissions()))
                ->where('selectedUser.lockedPermissions', [__(PermissionKey::RecordTransferPayments->label())])
                ->where('roleTemplates', []));
    }

    public function test_branch_admin_cannot_open_another_branchs_employee_in_the_editor(): void
    {
        $this->seedPermissions();
        $ownBranch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->grantingWhatTheyHold()->create(['branch_id' => $ownBranch->id]);
        $ownCollector = User::factory()->collector()->create(['branch_id' => $ownBranch->id]);
        $foreignCollector = User::factory()->collector()->create();

        $response = $this->actingAs($branchAdmin)->get(route('settings.permissions.edit', ['selected' => $foreignCollector->id]));

        $response->assertInertia(fn ($page) => $page->where('selectedUser.id', $ownCollector->id));
    }

    public function test_saving_returns_to_the_same_employee_and_filters(): void
    {
        $this->seedPermissions();
        $superAdmin = User::factory()->superAdmin()->create();
        $collector = User::factory()->collector()->create();
        $pageUrl = route('settings.permissions.edit', ['search' => 'col', 'selected' => $collector->id]);

        $response = $this->actingAs($superAdmin)->from($pageUrl)->put(route('settings.permissions.update'), [
            'permissions' => [$collector->id => []],
        ]);

        $response->assertRedirect($pageUrl);
    }

    public function test_collector_cannot_view_the_permissions_settings_page(): void
    {
        $this->seedPermissions();
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);

        $this->actingAs($collector)
            ->get(route('settings.permissions.edit'))
            ->assertForbidden();
    }

    public function test_permissions_page_can_be_searched_by_name_or_username(): void
    {
        $this->seedPermissions();
        $superAdmin = User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create();
        User::factory()->collector()->create(['branch_id' => $branch->id, 'name' => 'Findable Person', 'username' => 'findable']);
        User::factory()->collector()->create(['branch_id' => $branch->id, 'name' => 'Someone Else', 'username' => 'someone-else']);

        $response = $this->actingAs($superAdmin)->get(route('settings.permissions.edit', ['search' => 'Findable']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('users.data', 1)
            ->where('users.data.0.name', 'Findable Person'));
    }

    public function test_super_admin_can_grant_a_permission_to_a_collector(): void
    {
        $this->seedPermissions();
        $superAdmin = User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $viewBranches = Permission::where('key', PermissionKey::ViewBranches->value)->firstOrFail();

        $this->actingAs($superAdmin)->put(route('settings.permissions.update'), [
            'permissions' => [
                $collector->id => [$viewBranches->id],
            ],
        ])->assertRedirect(route('settings.permissions.edit'));

        $this->assertTrue($collector->fresh()->hasPermission(PermissionKey::ViewBranches));
    }

    public function test_a_page_load_reads_a_users_permissions_from_the_database_once(): void
    {
        $this->seedPermissions();
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $collector->permissions()->attach(Permission::where('key', PermissionKey::ViewBranches->value)->firstOrFail());
        $permissionQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$permissionQueries): void {
            if (str_contains($query->sql, 'permission_user')) {
                $permissionQueries++;
            }
        });

        $this->actingAs($collector)
            ->get(route('branches.index'))
            ->assertOk();

        $this->assertSame(1, $permissionQueries);
    }

    public function test_view_only_branches_permission_allows_viewing_but_not_creating(): void
    {
        $this->seedPermissions();
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $viewBranches = Permission::where('key', PermissionKey::ViewBranches->value)->firstOrFail();
        $collector->permissions()->attach($viewBranches);

        $this->actingAs($collector)
            ->get(route('branches.index'))
            ->assertOk();

        $this->actingAs($collector)->post(route('branches.store'), [
            'name' => 'Should Not Be Created',
            'is_active' => '1',
        ])->assertForbidden();
    }

    public function test_granting_create_and_update_branches_lets_a_collector_fully_manage_branches(): void
    {
        $this->seedPermissions();
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $createBranches = Permission::where('key', PermissionKey::CreateBranches->value)->firstOrFail();
        $updateBranches = Permission::where('key', PermissionKey::UpdateBranches->value)->firstOrFail();
        $collector->permissions()->attach([$createBranches->id, $updateBranches->id]);

        $this->actingAs($collector)
            ->get(route('branches.index'))
            ->assertOk();

        $this->actingAs($collector)->post(route('branches.store'), [
            'name' => 'Granted Branch',
            'is_active' => '1',
        ])->assertRedirect(route('branches.index'));

        $this->assertDatabaseHas('branches', ['name' => 'Granted Branch']);
    }

    public function test_collector_without_the_permission_still_cannot_manage_branches(): void
    {
        $this->seedPermissions();
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);

        $this->actingAs($collector)
            ->get(route('branches.index'))
            ->assertForbidden();
    }

    public function test_granting_update_users_lets_a_collector_view_and_update_users_in_their_own_branch_only(): void
    {
        $this->seedPermissions();
        $ownBranch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $collector = User::factory()->collector()->create(['branch_id' => $ownBranch->id]);
        $peer = User::factory()->collector()->create(['branch_id' => $ownBranch->id, 'name' => 'My Branch Peer']);
        $foreign = User::factory()->collector()->create(['branch_id' => $otherBranch->id, 'name' => 'Other Branch Peer']);
        $updateUsers = Permission::where('key', PermissionKey::UpdateUsers->value)->firstOrFail();
        $collector->permissions()->attach($updateUsers);

        $response = $this->actingAs($collector)->get(route('users.index'));
        $response->assertOk();
        $response->assertSee('My Branch Peer');
        $response->assertDontSee('Other Branch Peer');

        $this->actingAs($collector)
            ->put(route('users.update', $peer), [
                'name' => 'Updated Name',
                'username' => $peer->username,
            ])
            ->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', ['id' => $peer->id, 'name' => 'Updated Name']);

        $this->actingAs($collector)
            ->get(route('users.edit', $foreign))
            ->assertForbidden();
    }

    public function test_granting_update_users_does_not_grant_create_ability(): void
    {
        $this->seedPermissions();
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $updateUsers = Permission::where('key', PermissionKey::UpdateUsers->value)->firstOrFail();
        $collector->permissions()->attach($updateUsers);

        $this->actingAs($collector)
            ->get(route('users.create'))
            ->assertForbidden();
    }

    public function test_granting_subscription_permissions_lets_a_collector_manage_subscriptions(): void
    {
        $this->seedPermissions();
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $createSubscriptions = Permission::where('key', PermissionKey::CreateSubscriptions->value)->firstOrFail();
        $viewSubscriptions = Permission::where('key', PermissionKey::ViewSubscriptions->value)->firstOrFail();
        $collector->permissions()->attach([$createSubscriptions->id, $viewSubscriptions->id]);
        $tariff = Tariff::factory()->residential()->create();

        $this->actingAs($collector)
            ->get(route('subscriptions.index'))
            ->assertOk();

        $this->actingAs($collector)->post(route('subscriptions.store'), [
            'full_name' => 'Granted Subscription',
            'national_id' => '123456789',
            'initial_reading' => 100,
            'phone' => '0561000002',
            'address' => 'Some street',
            'tariff_id' => $tariff->id,
            'status' => SubscriptionStatus::Active->value,
            'minimum_charge' => 10,
            'notes' => 'No notes',
        ])->assertRedirect(route('subscriptions.index'));

        $this->assertDatabaseHas('subscriptions', ['national_id' => '123456789', 'branch_id' => $branch->id]);
    }

    public function test_collector_without_the_permission_still_cannot_manage_subscriptions(): void
    {
        $this->seedPermissions();
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);

        $this->actingAs($collector)
            ->get(route('subscriptions.index'))
            ->assertForbidden();
    }

    public function test_super_admin_implicitly_has_every_permission_without_any_grants(): void
    {
        $this->seedPermissions();
        $superAdmin = User::factory()->superAdmin()->create();

        foreach (PermissionKey::cases() as $key) {
            $this->assertTrue($superAdmin->hasPermission($key));
        }
    }

    public function test_saving_permissions_for_one_page_of_users_does_not_wipe_grants_of_users_on_another_page(): void
    {
        $this->seedPermissions();
        $superAdmin = User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create();

        $untouched = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $viewBranches = Permission::where('key', PermissionKey::ViewBranches->value)->firstOrFail();
        $untouched->permissions()->attach($viewBranches);

        $editedUser = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $viewSubscriptions = Permission::where('key', PermissionKey::ViewSubscriptions->value)->firstOrFail();

        // Only $editedUser is present in the payload — simulating a save
        // made while $untouched was on a different search result/page.
        $this->actingAs($superAdmin)->put(route('settings.permissions.update'), [
            'permissions' => [
                $editedUser->id => [$viewSubscriptions->id],
            ],
        ])->assertRedirect(route('settings.permissions.edit'));

        $this->assertTrue($editedUser->fresh()->hasPermission(PermissionKey::ViewSubscriptions));
        $this->assertTrue($untouched->fresh()->hasPermission(PermissionKey::ViewBranches));
    }
}
