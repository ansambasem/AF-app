<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\CampTwoCollectorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\TestCase;

class CampTwoCollectorSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_only_one_collector_with_payment_access_for_existing_camp_branch(): void
    {
        Storage::fake('local');
        $branch = Branch::factory()->create(['name' => 'مكتب المخيم المعدل', 'seed_key' => 'camp-two']);
        Permission::idsFor([PermissionKey::RecordCollections]);

        $this->seed(CampTwoCollectorSeeder::class);

        $user = User::query()->sole();
        $this->assertDatabaseHas('users', ['username' => CampTwoCollectorSeeder::USERNAME, 'branch_id' => $branch->id, 'role' => 'collector', 'is_active' => true]);
        $this->assertEqualsCanonicalizing(
            [PermissionKey::RecordCollections->value, PermissionKey::RecordCashPayments->value, PermissionKey::RecordTransferPayments->value],
            $user->permissions()->pluck('key')->all(),
        );
        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('subscription_transactions', 0);
        $this->assertDatabaseCount('cash_boxes', 0);
        $this->assertDatabaseCount('closing_settings', 0);
        $credentials = Storage::disk('local')->get(CampTwoCollectorSeeder::CREDENTIALS_PATH);
        preg_match('/Password: (.+)/', $credentials, $matches);
        $this->assertTrue(Hash::check($matches[1], $user->password));
        $this->actingAs($user)->get(route('payments.index'))->assertOk();
        $this->get(route('payments.summary'))->assertOk();
        $this->get(route('payments.cash-boxes'))->assertInertia(fn (AssertableInertia $page) => $page->component('Payments/CashBoxes'));
        $this->get(route('subscriptions.index'))->assertForbidden();
    }

    public function test_repeating_seeder_preserves_the_account_password_and_other_users(): void
    {
        Storage::fake('local');
        Branch::factory()->create(['name' => 'مخيم 2']);
        Permission::idsFor([PermissionKey::RecordCollections]);
        $other = User::factory()->superAdmin()->create();
        $this->seed(CampTwoCollectorSeeder::class);
        $collector = User::query()->where('username', CampTwoCollectorSeeder::USERNAME)->sole();
        $password = $collector->password;
        $credentials = Storage::disk('local')->get(CampTwoCollectorSeeder::CREDENTIALS_PATH);

        $this->seed(CampTwoCollectorSeeder::class);

        $this->assertDatabaseCount('users', 2);
        $this->assertSame($password, $collector->fresh()->password);
        $this->assertSame($credentials, Storage::disk('local')->get(CampTwoCollectorSeeder::CREDENTIALS_PATH));
        $this->assertDatabaseHas('users', ['id' => $other->id, 'role' => 'super_admin', 'username' => $other->username]);
    }

    public function test_seeder_refuses_to_replace_a_different_account_with_the_same_username(): void
    {
        Storage::fake('local');
        Branch::factory()->create(['name' => 'مخيم 2']);
        $other = User::factory()->superAdmin()->create(['username' => CampTwoCollectorSeeder::USERNAME]);

        try {
            $this->seed(CampTwoCollectorSeeder::class);
            $this->fail('The existing account must be preserved.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The collector username is already used by another account.', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $other->id, 'role' => 'super_admin']);
        Storage::disk('local')->assertMissing(CampTwoCollectorSeeder::CREDENTIALS_PATH);
    }
}
