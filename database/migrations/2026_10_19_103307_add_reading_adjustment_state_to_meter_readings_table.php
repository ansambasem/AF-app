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
        Schema::table('meter_readings', function (Blueprint $table): void {
            $table->decimal('next_previous_reading', 12, 2)->nullable();
            $table->boolean('requires_reentry')->default(false);
            $table->json('adjustment_history')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meter_readings', function (Blueprint $table): void {
            $table->dropColumn(['next_previous_reading', 'requires_reentry', 'adjustment_history']);
        });
    }
};
