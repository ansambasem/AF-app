<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each message as one subscription got it: the number it went to and the
     * text with their own details filled in, and whether it was sent.
     */
    public function up(): void
    {
        Schema::create('subscription_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->string('phone', 20);
            $table->text('body');
            $table->string('status')->default('pending');
            $table->string('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['message_batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_messages');
    }
};
