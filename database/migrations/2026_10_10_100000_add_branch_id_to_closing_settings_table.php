<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A branch may have its own closing schedule, set by its admin. The row
     * without a branch stays the company's: every branch that has no row of
     * its own follows it, as all of them did until now. The weekday that
     * starts the week stays the company's alone, since the weekly and monthly
     * closings approve every branch's days together, so a branch's row leaves
     * it empty.
     */
    public function up(): void
    {
        Schema::table('closing_settings', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->unique()->after('id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('week_starts_on')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('closing_settings')->whereNotNull('branch_id')->delete();

        Schema::table('closing_settings', function (Blueprint $table): void {
            // The foreign key goes first: MySQL will not drop the unique index it relies on, nor SQLite the column of an index.
            $table->dropForeign(['branch_id']);
            $table->dropUnique(['branch_id']);
            $table->dropColumn('branch_id');
            $table->unsignedTinyInteger('week_starts_on')->default(6)->nullable(false)->change();
        });
    }
};
