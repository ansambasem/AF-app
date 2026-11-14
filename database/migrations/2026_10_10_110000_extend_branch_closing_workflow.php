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
        Schema::table('closing_settings', function (Blueprint $table): void {
            $table->string('frequency')->default('weekly');
            $table->string('arrangement')->default('combined');
        });
        DB::table('closing_settings')->update(['frequency' => 'daily']);

        Schema::table('closings', function (Blueprint $table): void {
            $table->string('book')->default('combined');
            $table->boolean('is_manual')->default(false);
            $table->timestamp('coverage_from')->nullable();
            $table->timestamp('coverage_until')->nullable();
            $table->foreignId('branch_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('branch_approved_at')->nullable();
            $table->text('branch_notes')->nullable();
            $table->json('submitted_snapshot')->nullable();
            $table->index(['branch_id', 'coverage_until']);
            $table->dropUnique(['type', 'branch_id', 'period_start']);
            $table->unique(['branch_id', 'coverage_from', 'book']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('closings')->whereNotNull('coverage_from')->exists()) {
            throw new RuntimeException('Branch closing packages must be preserved; this migration cannot be rolled back after use.');
        }
        Schema::table('closings', function (Blueprint $table): void {
            $table->dropUnique(['branch_id', 'coverage_from', 'book']);
            $table->dropIndex(['branch_id', 'coverage_until']);
            $table->dropForeign(['branch_approved_by']);
            $table->dropColumn(['book', 'is_manual', 'coverage_from', 'coverage_until', 'branch_approved_by', 'branch_approved_at', 'branch_notes', 'submitted_snapshot']);
            $table->unique(['type', 'branch_id', 'period_start']);
        });
        Schema::table('closing_settings', function (Blueprint $table): void {
            $table->dropColumn(['frequency', 'arrangement']);
        });
    }
};
