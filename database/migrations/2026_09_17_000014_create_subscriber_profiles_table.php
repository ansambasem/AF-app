<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per person, shared by all of their subscriptions.
     */
    public function up(): void
    {
        Schema::create('subscriber_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('subscriber_number')->nullable()->unique();
            $table->string('full_name');
            $table->string('national_id', 9)->nullable()->unique();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriber_profiles');
    }
};
