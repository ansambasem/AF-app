<?php

use Carbon\CarbonInterface;
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
        Schema::create('reading_entry_settings', function (Blueprint $table) {
            $table->id();
            $table->json('open_days');
            $table->unsignedTinyInteger('reading_day')->default(CarbonInterface::THURSDAY);
            $table->json('reading_day_history')->nullable();
            $table->time('opens_at')->default('00:00:00');
            $table->time('closes_at')->default('23:59:00');
            $table->string('mode')->default('automatic');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reading_entry_settings');
    }
};
