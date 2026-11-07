<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One bank transfer divided between several subscriptions. It keeps what
     * the payer sent in one piece (the bank, the sender, the reference and
     * the total), while each subscription gets an ordinary payment of its
     * own share, linked back here so the parts can be shown, and matched
     * to the bank's one line, together.
     */
    public function up(): void
    {
        Schema::create('split_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference_number', 100);
            $table->string('bank_name');
            $table->string('sender_bank_name')->nullable();
            $table->string('sender_name');
            $table->decimal('total_amount', 12, 2);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table('subscription_transactions', function (Blueprint $table): void {
            $table->foreignId('split_payment_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('split_payment_id');
        });

        Schema::dropIfExists('split_payments');
    }
};
