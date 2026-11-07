<?php

namespace Database\Seeders;

use App\Enums\PermissionKey;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class CampTwoCollectorSeeder extends Seeder
{
    public const USERNAME = 'camp2_collector';

    public const CREDENTIALS_PATH = 'camp2-collector-login.txt';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('This collector seeder is only available locally or in tests.');
        }

        $branch = Branch::query()->where('seed_key', 'camp-two')->orWhereIn('name', ['مكتب مخيم 2', 'مخيم 2'])->sole();
        $existing = User::query()->where('username', self::USERNAME)->first();
        if ($existing) {
            if ($existing->branch_id !== $branch->id || $existing->role !== UserRole::Collector || $existing->name !== 'محصّل مخيم 2') {
                throw new RuntimeException('The collector username is already used by another account.');
            }
            $this->command?->info('Collector already exists; its password and permissions were kept.');

            return;
        }

        // Taking payments, both cash and bank transfers: a collector's starter set.
        $permissions = Permission::query()->whereIn('key', array_map(fn (PermissionKey $key): string => $key->value, UserRole::Collector->starterPermissions()))->pluck('id');
        $password = Str::password(20);
        DB::transaction(function () use ($branch, $permissions, $password): void {
            $user = User::withoutEvents(fn (): User => User::query()->create([
                'name' => 'محصّل مخيم 2',
                'username' => self::USERNAME,
                'password' => $password,
                'role' => UserRole::Collector,
                'branch_id' => $branch->id,
                'is_active' => true,
            ]));
            $user->permissions()->attach($permissions);
            Storage::disk('local')->put(self::CREDENTIALS_PATH, 'Username: '.self::USERNAME."\nPassword: {$password}\nBranch: مخيم 2\n");
        });

        $this->command?->info('Collector created. Local sign-in details: '.Storage::disk('local')->path(self::CREDENTIALS_PATH));
    }
}
