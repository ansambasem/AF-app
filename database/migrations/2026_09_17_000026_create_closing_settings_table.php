<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The company's closing schedule: when the business day closes, which
     * day starts the week, and whether each day's closings open by
     * themselves.
     */
    public function up(): void
    {
        Schema::create('closing_settings', function (Blueprint $table): void {
            $table->id();
            $table->time('cutoff_time')->default('00:00:00');
            $table->unsignedTinyInteger('week_starts_on')->default(6);
            $table->boolean('auto_open')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('closing_settings');
    }
};
