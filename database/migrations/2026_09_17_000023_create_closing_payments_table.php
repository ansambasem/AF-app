<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The payments a daily closing groups. A payment belongs to one daily
     * closing only, so it is never counted twice.
     */
    public function up(): void
    {
        Schema::create('closing_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('closing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->string('match_status')->nullable();
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('closing_payments');
    }
};
