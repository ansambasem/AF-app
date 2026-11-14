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
        Schema::create('meter_boxes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('name_suffix', 50)->nullable();
            $table->string('box_number')->unique();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('sub_area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location')->nullable();
            $table->timestamps();

            $table->unique(['name', 'name_suffix']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meter_boxes');
    }
};
