<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An entry the branch sent back to its collector before confirming the
     * box's close: who sent it, when and why, and the collector's answer.
     */
    public function up(): void
    {
        Schema::table('cash_box_payments', function (Blueprint $table) {
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('return_note')->nullable();
            $table->text('collector_reply')->nullable();
            $table->timestamp('replied_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cash_box_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn(['returned_at', 'return_note', 'collector_reply', 'replied_at']);
        });
    }
};
