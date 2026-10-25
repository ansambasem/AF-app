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
        Schema::table('subscription_transactions', function (Blueprint $table) {
            $table->date('bank_received_on')->nullable()->index();
        });
        Schema::table('split_payments', function (Blueprint $table) {
            $table->date('bank_received_on')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('split_payments', function (Blueprint $table) {
            $table->dropColumn('bank_received_on');
        });
        Schema::table('subscription_transactions', function (Blueprint $table) {
            $table->dropIndex(['bank_received_on']);
            $table->dropColumn('bank_received_on');
        });
    }
};
