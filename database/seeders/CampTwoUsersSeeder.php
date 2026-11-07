<?php

namespace Database\Seeders;

use App\Enums\PermissionKey;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CampTwoUsersSeeder extends Seeder
{
    /** @var list<array{name: string, username: string, role: UserRole, permissions: list<PermissionKey>|null}> */
    private const USERS = [
        ['name' => 'محمد', 'username' => 'muhammad', 'role' => UserRole::BranchAdmin, 'permissions' => null],
        ['name' => 'أحمد', 'username' => 'ahmed', 'role' => UserRole::Collector, 'permissions' => null],
        ['name' => 'محمود', 'username' => 'mahmoud', 'role' => UserRole::Collector, 'permissions' => null],
        ['name' => 'خليل', 'username' => 'khalil', 'role' => UserRole::DataEntry, 'permissions' => [
            PermissionKey::ViewMeterReadings, PermissionKey::RecordMeterReadings,
            PermissionKey::ViewReadingAssignments,
        ]],
        ['name' => 'علي', 'username' => 'ali', 'role' => UserRole::DataEntry, 'permissions' => [
            PermissionKey::ViewSubscriptions, PermissionKey::CreateSubscriptions, PermissionKey::UpdateSubscriptions,
        ]],
        ['name' => 'عمر', 'username' => 'omar', 'role' => UserRole::DataEntry, 'permissions' => [
            PermissionKey::ViewSubscriptions, PermissionKey::CreateSubscriptions, PermissionKey::UpdateSubscriptions,
        ]],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Sample users are only available locally or in tests.');
        }

        DB::transaction(function (): void {
            $branch = Branch::query()->where('seed_key', 'camp-two')->lockForUpdate()->first()
                ?? throw new RuntimeException('Run BranchSeeder before adding sample users.');

            foreach (self::USERS as $details) {
                $existing = User::query()->where('username', $details['username'])->first();

                if ($existing !== null) {
                    if ($existing->branch_id !== $branch->id || $existing->role !== $details['role'] || $existing->name !== $details['name']) {
                        throw new RuntimeException('The sample username '.$details['username'].' is already used by another account.');
                    }

                    continue;
                }

                $user = User::query()->create([
                    'name' => $details['name'],
                    'username' => $details['username'],
                    'password' => 'password',
                    'role' => $details['role'],
                    'branch_id' => $branch->id,
                    'is_active' => true,
                ]);

                $user->permissions()->sync(Permission::idsFor($details['permissions'] ?? $details['role']->starterPermissions()));
            }
        });

        $this->command?->info('Six camp-two sample accounts are ready. Existing accounts were kept.');
    }
}
