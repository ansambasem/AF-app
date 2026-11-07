<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The entry an audit note is about, when it is about one: the payment,
     * transfer or clearing the audit found missing or wrong. A note on a
     * whole box or week leaves it empty.
     */
    public function up(): void
    {
        Schema::table('audit_notes', function (Blueprint $table): void {
            $table->foreignId('cash_box_payment_id')->nullable()->after('cash_box_id')->constrained('cash_box_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_notes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cash_box_payment_id');
        });
    }
};
