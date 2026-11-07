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
        Schema::create('reading_distributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('meter_box_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table('reading_entry_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('assignment_lead_days')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reading_distributions');

        Schema::table('reading_entry_settings', function (Blueprint $table): void {
            $table->dropColumn('assignment_lead_days');
        });
    }
};
