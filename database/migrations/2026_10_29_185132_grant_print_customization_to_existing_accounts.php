<?php

use App\Enums\PermissionKey;
use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Changing a printout before printing it becomes a permission of its
     * own. Every account could do it until now, so each one keeps it, and
     * a manager who hands out permissions may hand this one out too; the
     * Super Admin takes it away from whoever should print the company's
     * templates as they are.
     */
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'key' => PermissionKey::CustomizePrintouts->value, 'label' => PermissionKey::CustomizePrintouts->label(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $permissionId = DB::table('permissions')->where('key', PermissionKey::CustomizePrintouts->value)->value('id');
        $managePermissionsId = DB::table('permissions')->where('key', PermissionKey::ManagePermissions->value)->value('id');
        $managers = $managePermissionsId === null ? collect() : DB::table('permission_user')->where('permission_id', $managePermissionsId)->pluck('user_id');

        DB::table('users')->where('role', '!=', UserRole::SuperAdmin->value)->orderBy('id')->select('id')
            ->chunk(500, function ($users) use ($permissionId, $managers): void {
                DB::table('permission_user')->insertOrIgnore($users->map(fn (object $user): array => [
                    'permission_id' => $permissionId, 'user_id' => $user->id, 'grantable' => $managers->contains($user->id),
                    'created_at' => now(), 'updated_at' => now(),
                ])->all());
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('key', PermissionKey::CustomizePrintouts->value)->value('id');
        DB::table('permission_user')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
