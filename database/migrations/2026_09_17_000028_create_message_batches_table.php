<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One send to a group of subscriptions: what it was about, how it went
     * out, and the wording it used. Its messages, one per subscription, are
     * in subscription_messages. The branch is empty when a Super Admin wrote
     * to subscriptions of several branches at once.
     */
    public function up(): void
    {
        Schema::create('message_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->string('channel');
            $table->text('body');
            $table->date('week_start')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_batches');
    }
};
