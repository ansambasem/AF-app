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
        Schema::create('bank_transfer_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_box_payment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('audited_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('adjustment_transaction_id')->nullable()->unique()->constrained('subscription_transactions')->restrictOnDelete();
            $table->string('currency', 3);
            $table->decimal('recorded_amount', 14, 2);
            $table->decimal('bank_amount', 14, 2);
            $table->decimal('exchange_rate', 14, 4);
            $table->decimal('difference', 14, 2);
            $table->string('outcome');
            $table->text('reason')->nullable();
            $table->timestamp('audited_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_transfer_audits');
    }
};
