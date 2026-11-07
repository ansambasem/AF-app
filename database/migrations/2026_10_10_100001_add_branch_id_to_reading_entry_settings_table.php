<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A branch may have its own reading schedule — reading day, entry days and
     * hours — set by its admin. The row without a branch stays the company's:
     * every branch that has no row of its own follows it, as all of them did
     * until now.
     */
    public function up(): void
    {
        Schema::table('reading_entry_settings', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->unique()->after('id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('reading_entry_settings')->whereNotNull('branch_id')->delete();

        Schema::table('reading_entry_settings', function (Blueprint $table): void {
            // The foreign key goes first: MySQL will not drop the unique index it relies on, nor SQLite the column of an index.
            $table->dropForeign(['branch_id']);
            $table->dropUnique(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
