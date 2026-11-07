<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reading cycles: a branch's reading week with the day its meters are
     * read, its meter boxes allocated each to one field reader or to the
     * paper sheets, the paper sheets printed for it, and every reading the
     * field app uploaded for it.
     */
    public function up(): void
    {
        Schema::create('reading_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('week_start');
            $table->date('week_end');
            // The day readings may be entered; the week's reading day unless moved for this cycle.
            $table->date('reading_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'week_start']);
            $table->index('reading_date');
        });

        Schema::create('reading_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reading_cycle_id')->constrained()->restrictOnDelete();
            $table->foreignId('meter_box_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            // mobile (to one field reader) or paper; empty while the box is back in the available pool.
            $table->string('channel')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status')->default('draft');
            // Raised on every change, so a field app holding an older version is told on upload.
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->unsignedInteger('downloaded_version')->nullable();
            $table->timestamps();

            // One allocation per box per cycle, enforced by the database.
            $table->unique(['reading_cycle_id', 'meter_box_id']);
            $table->index(['reading_cycle_id', 'channel', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('reading_assignment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reading_assignment_id')->constrained()->restrictOnDelete();
            $table->string('action');
            $table->string('from_channel')->nullable();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('to_channel')->nullable();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['reading_assignment_id', 'id']);
        });

        Schema::create('reading_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reading_cycle_id')->constrained()->restrictOnDelete();
            $table->foreignId('reading_assignment_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // The field app's id for the reading: an upload retried with it is answered, never saved twice.
            $table->uuid('operation_id')->unique();
            $table->unsignedInteger('assignment_version');
            $table->decimal('current_reading', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('received_at');
            $table->string('device_id', 100)->nullable();
            $table->string('status');
            $table->string('issue')->nullable();
            $table->foreignId('meter_reading_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['reading_cycle_id', 'status']);
            $table->index(['reading_assignment_id', 'subscription_id']);
        });

        Schema::create('paper_sheets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reading_cycle_id')->constrained()->restrictOnDelete();
            $table->string('sheet_number', 40)->unique();
            $table->unsignedInteger('print_count')->default(0);
            $table->timestamp('last_printed_at')->nullable();
            $table->foreignId('last_printed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('paper_sheet_assignments', function (Blueprint $table): void {
            $table->foreignId('paper_sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reading_assignment_id')->constrained()->restrictOnDelete();

            $table->primary(['paper_sheet_id', 'reading_assignment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_sheet_assignments');
        Schema::dropIfExists('paper_sheets');
        Schema::dropIfExists('reading_submissions');
        Schema::dropIfExists('reading_assignment_events');
        Schema::dropIfExists('reading_assignments');
        Schema::dropIfExists('reading_cycles');
    }
};
