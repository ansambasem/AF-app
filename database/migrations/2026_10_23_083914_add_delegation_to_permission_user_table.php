<?php

use App\Enums\PermissionKey;
use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each held permission records whether its holder may hand it on (a
     * right only the Super Admin gives) and who handed it to them, so it
     * goes when the giver loses it. Those who manage permissions today
     * keep handing on everything they hold.
     */
    public function up(): void
    {
        Schema::table('permission_user', function (Blueprint $table) {
            $table->boolean('grantable')->default(false)->after('user_id');
            $table->foreignId('granted_by')->nullable()->after('grantable')->constrained('users')->nullOnDelete();
        });

        $managePermissionsId = DB::table('permissions')->where('key', PermissionKey::ManagePermissions->value)->value('id');

        if ($managePermissionsId === null) {
            return;
        }

        $managers = DB::table('permission_user')
            ->join('users', 'users.id', '=', 'permission_user.user_id')
            ->where('permission_user.permission_id', $managePermissionsId)
            ->where('users.role', '!=', UserRole::SuperAdmin->value)
            ->pluck('users.id');

        DB::table('permission_user')
            ->whereIn('user_id', $managers)
            ->where('permission_id', '!=', $managePermissionsId)
            ->update(['grantable' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('permission_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('granted_by');
            $table->dropColumn('grantable');
        });
    }
};
