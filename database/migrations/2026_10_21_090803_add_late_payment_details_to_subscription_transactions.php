<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscription_transactions', function (Blueprint $table): void {
            $table->date('paid_on')->nullable()->index();
            $table->text('late_reason')->nullable();
            $table->uuid('late_payment_token')->nullable()->unique();
            $table->string('late_payment_fingerprint', 64)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_transactions', function (Blueprint $table): void {
            $table->dropIndex(['paid_on']);
            $table->dropUnique(['late_payment_token']);
            $table->dropColumn(['paid_on', 'late_reason', 'late_payment_token', 'late_payment_fingerprint']);
        });
    }
};
