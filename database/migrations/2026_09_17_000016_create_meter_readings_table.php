<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained();

            $table->date('week_start');
            $table->date('week_end');

            $table->decimal('previous_reading', 12, 2);
            $table->decimal('current_reading', 12, 2);
            $table->decimal('consumption', 12, 2);

            // Prices are copied onto the reading when it is recorded, so a
            // later tariff change never alters an old week's charges.
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('reading_fee', 10, 2)->default(0);
            $table->decimal('minimum_payment', 10, 2)->default(0);

            // The subscription's standing discount, copied the same way.
            $table->string('discount_method')->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->string('discount_segment', 100)->nullable();
            $table->decimal('discount_amount', 10, 2)->default(0);

            $table->decimal('amount_due', 10, 2)->default(0);

            $table->string('status')->default('pending');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();

            // Who approved a reading and when — the moment its charge reaches the subscription's transactions.
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->text('notes')->nullable();
            $table->uuid('mobile_operation_id')->nullable()->unique();
            $table->timestamps();

            $table->unique(['subscription_id', 'week_start']);
            $table->index(['branch_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_readings');
    }
};
