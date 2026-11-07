<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $permissions = [
                'users.manage_permissions' => 'إدارة صلاحيات موظفي الفرع',
                'users.manage_passwords' => 'تغيير كلمات مرور موظفي الفرع',
                'users.manage_branch_schedules' => 'إدارة مواعيد الفرع',
                'collections.manage_branch_closings' => 'إدارة واعتماد صناديق الفرع',
            ];
            foreach ($permissions as $key => $label) {
                DB::table('permissions')->insertOrIgnore(['key' => $key, 'label' => $label, 'created_at' => now(), 'updated_at' => now()]);
            }
            $permissionIds = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');
            foreach (['reports.view' => ['closings.view', 'closings.prepare', 'closings.audit', 'closings.view_all'], 'reports.view_all' => ['closings.audit', 'closings.view_all']] as $key => $legacyKeys) {
                DB::table('permissions')->insertOrIgnore(['key' => $key, 'label' => $key === 'reports.view' ? 'View Financial Reports' : 'View All Financial Reports', 'created_at' => now(), 'updated_at' => now()]);
                $reportId = DB::table('permissions')->where('key', $key)->value('id');
                $userIds = DB::table('permission_user')->join('permissions', 'permissions.id', '=', 'permission_user.permission_id')
                    ->join('users', 'users.id', '=', 'permission_user.user_id')->where('users.role', '!=', 'financial_auditor')
                    ->whereIn('permissions.key', $legacyKeys)->distinct()->pluck('users.id');
                foreach ($userIds as $userId) {
                    DB::table('permission_user')->insertOrIgnore(['user_id' => $userId, 'permission_id' => $reportId]);
                }
            }
            DB::table('users')->where('role', 'branch_admin')->orderBy('id')->chunkById(200, function (Collection $users) use ($permissionIds): void {
                $grants = [];
                foreach ($users as $user) {
                    foreach ($permissionIds as $permissionId) {
                        $grants[] = ['user_id' => $user->id, 'permission_id' => $permissionId];
                    }
                }
                DB::table('permission_user')->insertOrIgnore($grants);
            });
            foreach (['branch_admin' => 'مدير فرع', 'collector' => 'محصل', 'data_entry' => 'مدخل بيانات', 'accountant' => 'محاسب', 'financial_auditor' => 'مدقق مالي'] as $role => $name) {
                if (! DB::table('users')->where('role', $role)->whereNull('user_type_id')->exists()) {
                    continue;
                }
                DB::table('user_types')->insertOrIgnore(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('users')->where('role', $role)->whereNull('user_type_id')->update(['user_type_id' => DB::table('user_types')->where('name', $name)->value('id')]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        /** Job titles and preserved report grants remain to avoid discarding later edits. */
        DB::table('permissions')->whereIn('key', ['users.manage_permissions', 'users.manage_passwords', 'users.manage_branch_schedules', 'collections.manage_branch_closings'])->delete();
    }
};
