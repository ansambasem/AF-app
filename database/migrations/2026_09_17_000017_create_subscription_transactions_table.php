<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('meter_reading_id')->nullable()->constrained()->nullOnDelete();

            // A line is never edited or removed: reversing or correcting it
            // adds a later line that points back at it.
            $table->foreignId('reverses_id')->nullable()->constrained('subscription_transactions')->restrictOnDelete();
            $table->foreignId('corrects_id')->nullable()->constrained('subscription_transactions')->restrictOnDelete();
            $table->foreignId('reference_transaction_id')->nullable()->constrained('subscription_transactions')->restrictOnDelete();

            $table->string('type');
            $table->string('status')->default('active');
            $table->string('source_key')->unique();
            $table->uuid('mobile_operation_id')->nullable()->unique();

            $table->decimal('amount', 10, 2);
            $table->decimal('balance_after', 12, 2)->nullable();
            $table->string('currency', 3)->default('ILS');
            $table->decimal('currency_amount', 12, 2)->nullable();
            $table->decimal('exchange_rate', 12, 4)->default(1);

            $table->string('payment_method')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('sender_name')->nullable();
            $table->string('sender_bank_name')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('active_reference', 100)->nullable()->index();
            $table->unsignedInteger('voucher_number')->nullable()->unique();
            $table->string('manual_voucher_number')->nullable();
            $table->string('cash_box')->nullable();

            $table->string('discount_method')->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('discount_base', 12, 2)->nullable();

            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason')->nullable();
            $table->text('cancellation_notes')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'created_at', 'id'], 'subscription_transactions_history_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_transactions');
    }
};
