<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A closing groups existing payments for review: a branch's day, or the
     * whole company's week or month. It never creates or moves money.
     */
    public function up(): void
    {
        Schema::create('closings', function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();
            $table->string('type');
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('draft');
            $table->decimal('opening_cash', 12, 2)->nullable();
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->json('denominations')->nullable();
            $table->string('difference_reason')->nullable();
            $table->text('difference_notes')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();

            $table->unique(['type', 'branch_id', 'period_start']);
            $table->index(['type', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('closings');
    }
};
