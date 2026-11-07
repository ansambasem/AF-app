<?php

namespace Database\Seeders;

use App\Enums\TariffCategory;
use App\Enums\UserRole;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TeamTestingSeeder extends Seeder
{
    /** Prepare the shared local test fixtures without resetting existing data. */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Team test data is only available locally or in tests.');
        }

        DB::transaction(function (): void {
            if (! User::query()->where('role', UserRole::SuperAdmin)->where('is_active', true)->exists()) {
                $username = config('powercollect.super_admin.username');

                if (User::query()->where('username', $username)->exists()) {
                    throw new RuntimeException('The administrator username is already used by another account.');
                }

                User::factory()->superAdmin()->create([
                    'name' => config('powercollect.super_admin.name'),
                    'username' => $username,
                    'password' => config('powercollect.super_admin.password') ?: 'password',
                ]);
            }

            foreach (TariffCategory::cases() as $category) {
                Tariff::firstOrCreate(['category' => $category], ['rate' => 30]);
            }

            $this->call([
                PermissionSeeder::class,
                UserTypeSeeder::class,
                TariffSeeder::class,
                CircuitBreakerSeeder::class,
                CustomerSegmentSeeder::class,
                BranchSeeder::class,
                MeterBoxSeeder::class,
                CampTwoUsersSeeder::class,
                CampTwoSubscribersSeeder::class,
            ]);
        });
    }
}
