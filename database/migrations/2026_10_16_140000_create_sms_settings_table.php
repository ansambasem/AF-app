<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The company's SMS provider, set up from the dashboard: where and how
     * a message is sent, the sender name, and the credentials (kept
     * encrypted). One row for the company; while it is off or missing, the
     * gateway set in the server's environment (if any) is used.
     */
    public function up(): void
    {
        Schema::create('sms_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('provider_name', 100)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('method', 6)->default('post');
            $table->string('username_param', 50)->nullable();
            $table->string('username', 191)->nullable();
            $table->string('password_param', 50)->nullable();
            $table->text('password')->nullable();
            $table->text('token')->nullable();
            $table->string('to_param', 50)->default('to');
            $table->string('message_param', 50)->default('message');
            $table->string('sender_param', 50)->nullable();
            $table->string('sender', 20)->nullable();
            $table->string('phone_format', 20)->default('international');
            $table->string('country_code', 4)->default('970');
            $table->string('extra_params', 500)->nullable();
            $table->string('success_match', 100)->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_error', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_settings');
    }
};
