<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Enums\ReadingEntryMode;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\MeterReading;
use App\Models\ReadingEntrySetting;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\CampTwoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class CampTwoUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['muhammad', 'محمد', 'branch_admin'])]
    #[TestWith(['ahmed', 'أحمد', 'collector'])]
    #[TestWith(['mahmoud', 'محمود', 'collector'])]
    #[TestWith(['khalil', 'خليل', 'data_entry'])]
    #[TestWith(['ali', 'علي', 'data_entry'])]
    #[TestWith(['omar', 'عمر', 'data_entry'])]
    public function test_sample_user_can_sign_in_with_simple_username_and_password(string $username, string $name, string $role): void
    {
        $branch = Branch::factory()->create(['seed_key' => 'camp-two']);
        $this->seed(CampTwoUsersSeeder::class);
        $user = User::query()->where('username', $username)->sole();

        $this->assertDatabaseCount('users', 6);
        $this->assertSame($name, $user->name);
        $this->assertSame($role, $user->role->value);
        $this->assertSame($branch->id, $user->branch_id);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->post('/login', ['username' => $username, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_sample_staff_have_separate_collection_reading_and_subscription_access(): void
    {
        $branch = Branch::factory()->create(['seed_key' => 'camp-two']);
        ReadingEntrySetting::factory()->create(['branch_id' => $branch->id, 'mode' => ReadingEntryMode::Open]);
        $this->seed(CampTwoUsersSeeder::class);

        $manager = User::query()->where('username', 'muhammad')->sole();
        $this->assertTrue($manager->can('create', User::class));
        $this->assertTrue($manager->can('create', Subscription::class));
        $this->assertTrue($manager->can('create', MeterReading::class));

        foreach (['ahmed', 'mahmoud'] as $username) {
            $collector = User::query()->where('username', $username)->sole();
            $this->assertTrue($collector->hasPermission(PermissionKey::RecordCashPayments));
            $this->assertTrue($collector->hasPermission(PermissionKey::RecordTransferPayments));
            $this->assertFalse($collector->can('create', Subscription::class));
            $this->assertFalse($collector->can('create', MeterReading::class));
            $this->assertFalse($collector->can('create', User::class));
        }

        $reader = User::query()->where('username', 'khalil')->sole();
        $this->assertTrue($reader->can('create', MeterReading::class));
        $this->assertFalse($reader->hasPermission(PermissionKey::ApproveMeterReadings));
        $this->assertFalse($reader->hasPermission(PermissionKey::CorrectMeterReadings));
        $this->assertFalse($reader->can('create', Subscription::class));
        $this->actingAs($reader)->get(route('meter-readings.index'))->assertOk();
        $this->get(route('subscriptions.create'))->assertForbidden();
        $this->get(route('payments.index'))->assertForbidden();

        foreach (['ali', 'omar'] as $username) {
            $clerk = User::query()->where('username', $username)->sole();
            $this->assertTrue($clerk->can('create', Subscription::class));
            $this->assertFalse($clerk->can('create', MeterReading::class));
            $this->assertFalse($clerk->hasPermission(PermissionKey::RecordCollections));
            $this->assertFalse($clerk->can('create', User::class));
        }

        $this->actingAs($clerk)->get(route('subscriptions.create'))->assertOk();
        $this->get(route('meter-readings.index'))->assertForbidden();
    }

    public function test_repeating_seeder_preserves_passwords_permissions_and_existing_administrator(): void
    {
        Branch::factory()->create(['seed_key' => 'camp-two']);
        $administrator = User::factory()->superAdmin()->create();
        $originalAdministrator = $administrator->fresh()->getRawOriginal();
        $this->seed(CampTwoUsersSeeder::class);
        $reader = User::query()->where('username', 'khalil')->sole();
        $reader->update(['password' => 'changed-password', 'is_active' => false]);
        $reader->permissions()->detach();
        $originalReader = $reader->fresh()->getRawOriginal();

        $this->seed(CampTwoUsersSeeder::class);

        $this->assertDatabaseCount('users', 7);
        $this->assertSame($originalReader, $reader->fresh()->getRawOriginal());
        $this->assertSame(0, $reader->permissions()->count());
        $this->assertSame($originalAdministrator, $administrator->fresh()->getRawOriginal());
    }

    public function test_conflicting_username_rolls_back_all_new_accounts(): void
    {
        Branch::factory()->create(['seed_key' => 'camp-two']);
        $other = User::factory()->superAdmin()->create(['username' => 'omar']);

        try {
            $this->seed(CampTwoUsersSeeder::class);
            $this->fail('A conflicting username must not be replaced.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The sample username omar is already used by another account.', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertSame(UserRole::SuperAdmin, $other->fresh()->role);
    }

    public function test_missing_branch_does_not_create_accounts_or_a_branch(): void
    {
        try {
            $this->seed(CampTwoUsersSeeder::class);
            $this->fail('The camp branch is required.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Run BranchSeeder before adding sample users.', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('branches', 0);
    }

    public function test_sample_users_cannot_be_seeded_in_production(): void
    {
        app()->instance('env', 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sample users are only available locally or in tests.');

        app(CampTwoUsersSeeder::class)->run();
    }
}
