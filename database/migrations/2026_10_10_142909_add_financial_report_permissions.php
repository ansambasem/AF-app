<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $timestamp = now();

        DB::table('permissions')->insertOrIgnore([
            ['key' => 'reports.view', 'label' => 'View Financial Reports', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['key' => 'reports.view_all', 'label' => 'View All Financial Reports', 'created_at' => $timestamp, 'updated_at' => $timestamp],
        ]);
    }

    /**
     * Preserve permission definitions and any grants made after this migration.
     */
    public function down(): void
    {
        throw new RuntimeException('Financial report permissions and their grants must be preserved; use a forward migration.');
    }
};
