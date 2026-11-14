<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add the permission to close, count and receive the branch's cash boxes,
     * and give it to every Branch Admin, who closed boxes before it existed.
     */
    public function up(): void
    {
        $timestamp = now();

        DB::table('permissions')->insertOrIgnore([
            ['key' => 'collections.close_boxes', 'label' => 'Close Cash Boxes', 'created_at' => $timestamp, 'updated_at' => $timestamp],
        ]);

        $permissionId = DB::table('permissions')->where('key', 'collections.close_boxes')->value('id');

        DB::table('permission_user')->insertOrIgnore(DB::table('users')->where('role', 'branch_admin')->pluck('id')->map(fn (int $userId): array => [
            'permission_id' => $permissionId,
            'user_id' => $userId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->all());
    }

    /**
     * Preserve the permission and any grants made after this migration.
     */
    public function down(): void
    {
        throw new RuntimeException('The Close Cash Boxes permission and its grants must be preserved; use a forward migration.');
    }
};
