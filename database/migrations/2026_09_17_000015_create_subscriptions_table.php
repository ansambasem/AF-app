<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            // Nullable only so the row can be inserted first: the model
            // assigns the account number itself while creating.
            $table->string('account_number', 20)->nullable()->unique();

            $table->foreignId('subscriber_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('full_name');
            $table->string('national_id', 9)->nullable()->index();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('subscription_name')->nullable();
            $table->string('subscription_phone', 10)->nullable();

            $table->foreignId('branch_id')->constrained();

            // Nullable: a subscription can be registered before a meter box is
            // assigned to them.
            $table->foreignId('meter_box_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('tariff_id')->constrained();

            // Nullable: a subscription without a segment is simply its tariff's category (e.g. plain Residential).
            $table->foreignId('tariff_segment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('circuit_breaker_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('minimum_charge', 10, 2)->nullable();
            $table->decimal('initial_reading', 12, 2)->nullable();
            $table->decimal('subscription_fee', 10, 2)->nullable();
            $table->date('subscription_date')->nullable();

            // The first time the subscription was active, so one who was never active can be told from one who was.
            $table->timestamp('activated_at')->nullable();

            $table->string('status')->default('active');
            $table->foreignId('registered_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
