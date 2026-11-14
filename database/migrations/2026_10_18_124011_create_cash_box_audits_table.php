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
        Schema::create('cash_box_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_box_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('audited_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('audited_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_box_audits');
    }
};
