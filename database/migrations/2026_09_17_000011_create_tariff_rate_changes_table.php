<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each kilo price a tariff has had, when it was set and by whom, so the
     * tariffs page can show since when the price applies and its history.
     * Existing tariffs start with their current price, from when they were
     * last saved, by nobody known.
     */
    public function up(): void
    {
        Schema::create('tariff_rate_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tariff_id')->constrained()->cascadeOnDelete();
            $table->decimal('rate', 10, 2);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tariff_rate_changes');
    }
};
