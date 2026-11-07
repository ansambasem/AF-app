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
        Schema::table('closing_settings', function (Blueprint $table): void {
            $table->timestamp('workflow_started_at')->nullable();
            $table->json('receivers')->nullable();
            $table->json('approvers')->nullable();
        });
        Schema::table('closings', function (Blueprint $table): void {
            $table->json('workflow_settings')->nullable();
            $table->timestamp('scheduled_until')->nullable();
        });
        Schema::table('cash_transfers', function (Blueprint $table): void {
            $table->string('currency', 3)->default('ILS');
        });
        Schema::create('cash_boxes', function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind')->default('staff');
            $table->string('open_key')->nullable()->unique();
            $table->foreignId('closing_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason')->nullable();
            $table->json('collector_counts')->nullable();
            $table->json('head_counts')->nullable();
            $table->json('expected_counts')->nullable();
            $table->json('decisions')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });
        Schema::create('cash_box_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_box_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->string('currency', 3);
            $table->decimal('amount', 12, 2);
            $table->string('method');
            $table->string('status')->default('pending');
            $table->timestamp('occurred_at');
            $table->timestamp('synced_at');
            $table->boolean('is_late')->default(false);
            $table->foreignId('confirmed_in_closing_id')->nullable()->constrained('closings')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('closing_setting_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->json('settings');
            $table->timestamp('effective_from');
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('collector_debts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_box_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('currency', 3);
            $table->decimal('amount', 12, 2);
            $table->decimal('remaining', 12, 2);
            $table->timestamps();
            $table->unique(['cash_box_id', 'currency']);
        });
        Schema::create('debt_repayments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collector_debt_id')->constrained()->restrictOnDelete();
            $table->foreignId('closing_id')->constrained()->restrictOnDelete();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });
        Schema::create('safe_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('closing_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->json('counts');
            $table->json('expected');
            $table->json('differences');
            $table->text('reason')->nullable();
            $table->timestamps();
        });
        Schema::create('closing_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('closing_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->unique(['closing_id', 'version']);
        });
        Schema::create('audit_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('closing_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_box_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('body');
            $table->json('differences')->nullable();
            $table->json('difference_decisions')->nullable();
            $table->string('status')->default('awaiting_reply');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('audit_note_replies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('audit_note_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->text('body');
            $table->string('document_path')->nullable();
            $table->timestamps();
        });
        Schema::create('closing_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('closing_id')->constrained()->restrictOnDelete();
            $table->foreignId('original_closing_id')->constrained('closings')->restrictOnDelete();
            $table->foreignId('audit_note_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('book');
            $table->string('currency', 3);
            $table->decimal('amount', 12, 2);
            $table->text('reason');
            $table->timestamps();
        });
        Schema::create('cash_box_syncs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('synced_at');
            $table->unsignedInteger('pending_payments')->default(0);
            $table->timestamps();
            $table->unique(['branch_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['cash_box_syncs', 'closing_adjustments', 'audit_note_replies', 'audit_notes', 'closing_versions', 'safe_counts', 'debt_repayments', 'collector_debts', 'cash_box_payments', 'cash_boxes', 'closing_setting_changes'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('cash_transfers', fn (Blueprint $table) => $table->dropColumn('currency'));
        Schema::table('closings', fn (Blueprint $table) => $table->dropColumn(['workflow_settings', 'scheduled_until']));
        Schema::table('closing_settings', fn (Blueprint $table) => $table->dropColumn(['workflow_started_at', 'receivers', 'approvers']));
    }
};
