<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Excel layouts shared by the whole company: each belongs to one kind
     * of export (`kind`, such as the reports page) and holds the Excel
     * editor's settings — the parts, columns, totals and look, never a
     * file's title or name. One per kind may be its default.
     */
    public function up(): void
    {
        Schema::create('excel_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 60);
            $table->string('name', 60);
            $table->json('settings');
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['kind', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excel_templates');
    }
};
