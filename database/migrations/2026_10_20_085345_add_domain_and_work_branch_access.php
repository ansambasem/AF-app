<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('domain', 253)->nullable()->unique();
            $table->boolean('domain_enabled')->default(false);
        });
        Schema::create('branch_user', function (Blueprint $table): void {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['branch_id', 'user_id']);
        });
        DB::table('permissions')->insertOrIgnore([
            'key' => 'branches.manage_domains', 'label' => 'إدارة روابط الفروع', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('permissions')->where('key', 'branches.manage_domains')->delete();
        Schema::dropIfExists('branch_user');
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropUnique(['domain']);
            $table->dropColumn(['domain', 'domain_enabled']);
        });
    }
};
