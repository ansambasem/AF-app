<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    private const DATA_ENTRY_STARTER_KEYS = [
        'subscriptions.view', 'subscriptions.create', 'subscriptions.update', 'subscriptions.bulk_update', 'meter_readings.view', 'meter_readings.record', 'meter_readings.correct',
        'meter_readings.correct_last', 'meter_readings.browse_weeks', 'reading_assignments.view', 'reading_assignments.print_sheets',
    ];

    public function test_a_new_user_starts_without_automatic_permissions(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($superAdmin)->post(route('users.store'), [
            'name' => 'Dana Entry',
            'username' => 'dana.entry',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'branch_id' => $branch->id,
            'is_active' => '1',
        ])->assertRedirect(route('users.index'));

        $this->assertEqualsCanonicalizing(
            [],
            User::where('username', 'dana.entry')->firstOrFail()->permissions->pluck('key')->all(),
        );
    }

    public function test_changing_legacy_role_metadata_does_not_replace_individual_permissions(): void
    {
        $collector = User::factory()->collector()->create();
        $collector->permissions()->attach(Permission::idsFor([PermissionKey::ViewBranches]));
        $before = $collector->permissions()->pluck('key')->all();

        $collector->update(['role' => UserRole::DataEntry]);

        $this->assertEqualsCanonicalizing($before, $collector->fresh()->permissions->pluck('key')->all());
    }

    public function test_editing_a_user_without_changing_their_role_keeps_their_permissions(): void
    {
        $dataEntry = User::factory()->dataEntry()->create();
        $dataEntry->permissions()->attach(Permission::idsFor([PermissionKey::ViewTariffs]));

        $dataEntry->update(['name' => 'Renamed']);

        $this->assertTrue($dataEntry->fresh()->hasPermission(PermissionKey::ViewTariffs));
    }
}
