<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_form_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('category', 100);
            $table->json('layout');
            $table->unsignedInteger('version')->default(1);
            $table->string('source_disk')->nullable();
            $table->string('source_path')->nullable();
            $table->string('source_name')->nullable();
            $table->string('source_mime', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_form_templates');
    }
};
