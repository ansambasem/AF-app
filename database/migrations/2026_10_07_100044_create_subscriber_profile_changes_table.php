<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who changed a person's personal details, when, and what they were
     * before. The details are the person's, shared by all their
     * subscriptions (in any branch), so the history is the person's too.
     */
    public function up(): void
    {
        Schema::create('subscriber_profile_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscriber_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->json('changes');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subscriber_profile_id', 'created_at'], 'profile_changes_profile_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriber_profile_changes');
    }
};
