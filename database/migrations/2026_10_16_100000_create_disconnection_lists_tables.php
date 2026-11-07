<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Disconnection lists: each branch keeps its own lists of subscriptions
     * to disconnect, managed by hand. Nothing here disconnects anyone or
     * charges anything by itself; the history of every list item is kept and
     * never deleted.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            // The subscriber's own disconnection fee; empty means the company's usual fee.
            $table->decimal('disconnection_fee', 10, 2)->nullable()->after('kilowatt_price');
        });

        Schema::create('disconnection_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('title', 150);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'closed_at']);
        });

        Schema::create('disconnection_list_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('disconnection_list_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();

            // Set to the subscription while the item is scheduled, so a subscription is never on two lists for the same disconnection.
            $table->unsignedBigInteger('active_subscription_id')->nullable()->unique();

            $table->string('reason');
            $table->text('note')->nullable();
            $table->date('scheduled_for');
            $table->string('status')->default('scheduled');
            $table->decimal('balance_when_added', 12, 2)->default(0);
            $table->foreignId('added_by')->constrained('users')->restrictOnDelete();

            $table->unsignedInteger('extensions_count')->default(0);
            $table->unsignedInteger('warnings_count')->default(0);
            $table->timestamp('last_warned_at')->nullable();

            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('removal_reason')->nullable();

            // The confirmed disconnection: when, by whom, and the fee charged for it (a snapshot), with its account line.
            $table->timestamp('disconnected_at')->nullable();
            $table->foreignId('disconnected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('fee_amount', 10, 2)->nullable();
            $table->foreignId('fee_transaction_id')->nullable()->constrained('subscription_transactions')->restrictOnDelete();
            $table->timestamps();

            $table->index(['disconnection_list_id', 'status']);
            $table->index(['subscription_id', 'status']);
            $table->index(['branch_id', 'status', 'scheduled_for']);
        });

        Schema::create('disconnection_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('disconnection_list_item_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('subscription_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['disconnection_list_item_id', 'id']);
            $table->index(['type', 'created_at']);
        });

        Schema::table('message_batches', function (Blueprint $table): void {
            // Sent again with the same id (a double click, a retried request), the send is not repeated.
            $table->uuid('operation_id')->nullable()->unique()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('message_batches', function (Blueprint $table): void {
            $table->dropUnique(['operation_id']);
            $table->dropColumn('operation_id');
        });
        Schema::dropIfExists('disconnection_events');
        Schema::dropIfExists('disconnection_list_items');
        Schema::dropIfExists('disconnection_lists');
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('disconnection_fee');
        });
    }
};
