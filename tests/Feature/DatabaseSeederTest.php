<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_prepares_operational_data_without_demo_subscribers_or_transactions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(User::where('role', UserRole::SuperAdmin)->exists());
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('branches', 5);
        $this->assertDatabaseCount('meter_boxes', 969);
        $this->assertDatabaseCount('tariffs', 2);
        $this->assertDatabaseCount('circuit_breakers', 11);
        $this->assertDatabaseCount('tariff_segments', 12);
        $this->assertDatabaseHas('tariff_segments', ['name' => 'مساجد']);
        $this->assertDatabaseHas('permissions', ['key' => PermissionKey::CreateSubscriptions->value]);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('subscription_transactions', 0);
        $this->assertDatabaseCount('cash_boxes', 0);
    }

    public function test_seeded_branch_staff_start_with_their_roles_usual_permissions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $branchAdmin = User::where('role', UserRole::BranchAdmin)->firstOrFail();
        $this->assertTrue($branchAdmin->hasPermission(PermissionKey::CreateSubscriptions));
        $this->assertTrue($branchAdmin->hasPermission(PermissionKey::ViewTariffs));
        $this->assertFalse($branchAdmin->hasPermission(PermissionKey::CreateTariffs));
        $this->assertDatabaseMissing('users', ['role' => UserRole::DataEntry->value]);
    }
}
