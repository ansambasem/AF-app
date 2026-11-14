<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saved print designs, shared by the whole company: each belongs to one
     * list (`page`, its address such as /meter-readings) and holds the
     * print designer's whole layout. One per list may be its default.
     */
    public function up(): void
    {
        Schema::create('print_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('page', 100);
            $table->string('name', 100);
            $table->json('layout');
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['page', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_templates');
    }
};
