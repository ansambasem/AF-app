<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash a branch hands over to the company after its daily closing: an
     * internal transfer, never a new collection.
     */
    public function up(): void
    {
        Schema::create('cash_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('closing_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method');
            $table->foreignId('sent_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at');
            $table->string('proof_path');
            $table->text('notes')->nullable();
            $table->string('status')->default('in_transit');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transfers');
    }
};
