<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Remove «تأكيد التحصيلات»: nothing ever checked it, so granting it changed nothing.
     */
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('key', 'collections.confirm')->value('id');
        if ($permissionId !== null) {
            DB::table('permission_user')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'key' => 'collections.confirm', 'label' => 'Confirm Collections',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};
