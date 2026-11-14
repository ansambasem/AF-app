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
        Schema::table('bank_transfer_audits', function (Blueprint $table) {
            $table->timestamp('posted_at')->nullable();
        });

        DB::table('bank_transfer_audits')->update(['posted_at' => DB::raw('audited_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_transfer_audits', function (Blueprint $table) {
            $table->dropColumn('posted_at');
        });
    }
};
