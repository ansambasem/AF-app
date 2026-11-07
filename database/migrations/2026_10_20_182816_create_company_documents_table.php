<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->foreignId('company_form_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mode', 20);
            $table->string('title', 150);
            $table->string('paper_reference', 100)->nullable();
            $table->date('paper_date')->nullable();
            $table->text('notes')->nullable();
            $table->json('snapshot');
            $table->string('attachment_disk')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'id']);
            $table->index(['subscription_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_documents');
    }
};
