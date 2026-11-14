<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A subscription's standing discount (خصم دائم): taken off every weekly
     * reading recorded while it lasts, by percentage, free kilowatts or
     * shekels off the kilo price. A subscription has one at most.
     */
    public function up(): void
    {
        Schema::create('standing_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('method');
            $table->decimal('value', 12, 2);
            $table->string('segment', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('standing_discounts');
    }
};
