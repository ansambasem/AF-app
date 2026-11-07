<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer segments (e.g. mosques, schools): labels for grouping
        // subscriptions, not separate prices. Any subscription can be given any
        // segment, so a segment belongs to no tariff.
        Schema::create('tariff_segments', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariff_segments');
    }
};
