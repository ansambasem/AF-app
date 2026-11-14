<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep the last issued voucher number outside the transaction rows, so
     * permanently erasing a payment can never make its number reusable.
     */
    public function up(): void
    {
        Schema::create('payment_voucher_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('last_number');
        });

        DB::table('payment_voucher_sequences')->insert(['id' => 1, 'last_number' => 0]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_voucher_sequences');
    }
};
