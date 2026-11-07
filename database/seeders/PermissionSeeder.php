<?php

namespace Database\Seeders;

use App\Enums\PermissionKey;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $currentKeys = array_map(fn (PermissionKey $key) => $key->value, PermissionKey::cases());

        foreach (PermissionKey::cases() as $key) {
            if ($key->isRetired()) {
                continue;
            }

            Permission::updateOrCreate(
                ['key' => $key->value],
                ['label' => $key->label()],
            );
        }

        // Drop permissions from a retired key (e.g. a renamed or removed
        // enum case) so the Permissions matrix never shows a stale column.
        Permission::whereNotIn('key', $currentKeys)->delete();
    }
}
