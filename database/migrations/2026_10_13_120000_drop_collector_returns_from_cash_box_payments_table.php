<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The branch no longer sends entries back to their collector: it talks
     * to them, and the collector corrects the entry before the box's close
     * is confirmed. What was sent back, and the answers, go.
     */
    public function up(): void
    {
        Schema::table('cash_box_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn(['returned_at', 'return_note', 'collector_reply', 'replied_at']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_box_payments', function (Blueprint $table) {
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('return_note')->nullable();
            $table->text('collector_reply')->nullable();
            $table->timestamp('replied_at')->nullable();
        });
    }
};
