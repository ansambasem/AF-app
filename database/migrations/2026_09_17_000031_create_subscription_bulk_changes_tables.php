<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Changes applied to many subscriptions at once from the subscriptions
     * list (their minimum charge, their status), kept with each
     * subscription's value before and after, so a change can be reviewed
     * and undone. The branch is empty when a Super Admin changed
     * subscriptions of several branches.
     */
    public function up(): void
    {
        Schema::create('subscription_bulk_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('field');
            $table->string('value')->nullable();
            $table->string('description');
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('changed_count')->default(0);
            $table->timestamp('undone_at')->nullable();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('restored_count')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_bulk_change_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_bulk_change_id')->constrained(indexName: 'bulk_change_items_change_id_foreign')->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_bulk_change_items');
        Schema::dropIfExists('subscription_bulk_changes');
    }
};
